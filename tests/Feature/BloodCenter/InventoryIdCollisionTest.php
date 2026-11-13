<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\BloodCollection;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Repository\InventoryRepository;
use App\Support\OperationalDay;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CompilesPostgresQueries;
use Tests\TestCase;

/**
 * Every branch the unit-id race can reach, taken deterministically.
 *
 * True parallel requests are not reachable from this suite: phpunit.xml runs
 * sqlite :memory:, where a second connection cannot see the first's database
 * and lockForUpdate() compiles to nothing. Saying so is more useful than a test
 * that only appears to prove concurrency, so the lock is proven by compiling it
 * against the pgsql grammar and the recovery paths are proven by making each
 * collision happen on purpose.
 *
 * A concurrent commit is simulated in two halves, because a row inserted inside
 * the failing transaction would vanish with it: the colliding row is written
 * from underneath the insert to cause a real unique violation, then written
 * again once the rollback has finished, which is where a competing request's
 * row would have been all along.
 *
 * Numbering itself is covered in InventoryIntakeTest: a bag takes its
 * donation's number, [centre]-[barcode]-[component], and a second bag of the
 * same component ends -2. With nothing generated there is nothing to retry: a
 * number already taken is refused.
 */
class InventoryIdCollisionTest extends TestCase
{
    use CompilesPostgresQueries, LazilyRefreshDatabase;

    private User $staff;

    private int $facilityId;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->bloodCenterStaff()->create();
        $this->facilityId = $this->staff->facility_id;

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC', 'code' => 'PRBC']);

        Facility::whereKey($this->facilityId)->update(['abbreviation' => 'SNBC']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);

        // Cleared and labelled: the only donation Stock Intake books in.
        $this->donation = Donation::factory()->handedOver()->create([
            'facility_id' => $this->facilityId,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        // The laboratory's declaration of what this donation was separated
        // into. Intake is constrained to it, so without this row there is
        // nothing inventory is allowed to book in. Quantity is generous
        // because several of these tests record more than one unit.
        DonationComponent::factory()->quantity(10)->create([
            'donation_id' => $this->donation->id,
            'component_id' => $this->component->id,
            'declared_by' => $this->staff->id,
        ]);

        BloodCollection::where('donation_id', $this->donation->id)->update(['donation_barcode' => '21627990']);
    }

    public function test_intake_asks_for_the_donation_row_lock(): void
    {
        // Driver-honest, and it catches the refactor that drops the lock while
        // the code still reads fine: the sequence is namespaced by donation, so
        // the donation row is what concurrent intake has to serialise on.
        $queries = $this->compiledOnPostgres(
            fn () => app(InventoryRepository::class)->lockDonation($this->donation->id, $this->facilityId)
        );

        $this->assertStringEndsWith('for update', $queries[0]['query']);
    }

    public function test_a_bag_number_taken_from_underneath_is_refused_cleanly(): void
    {
        $attempts = $this->countInsertAttempts();
        $this->stealTheFirstUnitId();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'bag_number_taken');

        // Not a 500, and not retried: the number is the donation's, so a
        // retry could only collide again.
        $this->assertSame(1, $attempts->value);
    }

    public function test_a_bag_number_already_in_stock_is_refused_before_anything_is_written(): void
    {
        // The same sticker booked under another donation at this centre.
        $this->seedUnit('SNBC-21627990-PRBC', $this->otherDonationAtThisFacility()->id);

        $attempts = $this->countInsertAttempts();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'bag_number_taken')
            ->assertJsonPath('message', 'Bag SNBC-21627990-PRBC already exists. Check the sticker on the bag.');

        $this->assertSame(0, $attempts->value);
    }

    /**
     * Count how many unit inserts the request attempts.
     *
     * Returned as an object so the closure and the assertion share one value:
     * this is what proves a taken number is refused rather than retried.
     */
    private function countInsertAttempts(): object
    {
        $counter = new class
        {
            public int $value = 0;
        };

        BloodUnit::creating(function () use ($counter): void {
            $counter->value++;
        });

        return $counter;
    }

    /**
     * Take the id an insert is about to use, the way a competing request would.
     *
     * Written twice on purpose. The first write is inside the caller's
     * transaction, so the insert that follows hits a real unique violation from
     * the real driver; the second is on the TransactionRolledBack event, once
     * that transaction has been unwound and the row can persist — which is where
     * a concurrent request's committed row would have been all along.
     *
     * @param  int  $skip  how many inserts to let through before stealing one
     */
    private function stealTheFirstUnitId(int $skip = 0): void
    {
        $stolenId = null;
        $seen = 0;
        $replayed = false;

        BloodUnit::creating(function (BloodUnit $unit) use (&$stolenId, &$seen, $skip): void {
            if ($stolenId !== null || $seen++ < $skip) {
                return;
            }

            $stolenId = $unit->id;

            $this->seedUnit($stolenId, $this->donation->id);
        });

        Event::listen(TransactionRolledBack::class, function () use (&$stolenId, &$replayed): void {
            if ($stolenId === null || $replayed) {
                return;
            }

            $replayed = true;

            $this->seedUnit($stolenId, $this->donation->id);
        });
    }

    /**
     * Insert a unit row directly, bypassing the model events under test.
     */
    private function seedUnit(string $id, int $donationId): void
    {
        DB::table('blood_units')->insert([
            'id' => $id,
            'facility_id' => $this->facilityId,
            'component_id' => $this->component->id,
            'blood_type_id' => $this->bloodType->id,
            'donation_id' => $donationId,
            'expiry_date' => $this->inDays(30),
            'status' => BloodUnitStatus::Available->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function otherDonationAtThisFacility(): Donation
    {
        return Donation::factory()->create([
            'facility_id' => $this->facilityId,
            'donor_id' => $this->donorProfile->donor_id,
            'status' => 'completed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'donation_id' => $this->donation->id,
            'units' => [
                [
                    'component_id' => $this->component->id,
                    'storage_location' => 'Cold Storage A-1',
                    'expiry_date' => $this->inDays(30),
                ],
            ],
        ];
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
