<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The scheduled sweep that moves past-expiry stock off the shelf.
 *
 * The stored status is the single truth about what a unit is, which is why the
 * listing never re-labels one on the fly — this is what moves it, and these
 * tests are what say it moves the right rows and leaves a trail per bag.
 */
class ExpireBloodUnitsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private int $facilityId;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facilityId = Facility::factory()->approved()->create()->id;

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_expires_only_past_expiry_available_units(): void
    {
        $this->makeUnit(['id' => 'DUE-01', 'expiry_date' => $this->inDays(-1)]);
        $this->makeUnit(['id' => 'TODAY-01', 'expiry_date' => $this->inDays(0)]);
        $this->makeUnit(['id' => 'FUTURE-01', 'expiry_date' => $this->inDays(10)]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(BloodUnitStatus::Expired, $this->unit('DUE-01')->status);
        $this->assertSame(BloodUnitStatus::Available, $this->unit('TODAY-01')->status);
        $this->assertSame(BloodUnitStatus::Available, $this->unit('FUTURE-01')->status);
    }

    public function test_a_unit_expiring_today_is_not_swept_the_night_it_was_recorded(): void
    {
        // The other half of D8. Intake accepts today's date because a bag
        // stamped today is usable for the rest of today; the sweep must not take
        // it away the same night.
        $this->makeUnit(['id' => 'TODAY-01', 'expiry_date' => $this->inDays(0)]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $unit = $this->unit('TODAY-01');

        $this->assertSame(BloodUnitStatus::Available, $unit->status);
        $this->assertNull($unit->expired_at);
    }

    public function test_it_stamps_when_the_unit_expired(): void
    {
        $this->makeUnit(['id' => 'DUE-01', 'expiry_date' => $this->inDays(-2)]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertNotNull($this->unit('DUE-01')->expired_at);
    }

    public function test_it_leaves_reserved_issued_and_discarded_units_alone(): void
    {
        // A reserved unit that passes its expiry has to be released from its
        // allocation before it can be expired, and releasing is the allocation
        // module's business. Recorded as a conflict rather than half-solved here.
        foreach ([BloodUnitStatus::Reserved, BloodUnitStatus::Issued, BloodUnitStatus::Discarded] as $status) {
            $this->makeUnit([
                'id' => 'PAST-'.$status->value,
                'expiry_date' => $this->inDays(-5),
                'status' => $status,
            ]);
        }

        $this->artisan('inventory:expire-units')->assertSuccessful();

        foreach ([BloodUnitStatus::Reserved, BloodUnitStatus::Issued, BloodUnitStatus::Discarded] as $status) {
            $unit = $this->unit('PAST-'.$status->value);

            $this->assertSame($status, $unit->status);
            $this->assertNull($unit->expired_at);
        }
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->makeUnit(['id' => 'DUE-01', 'expiry_date' => $this->inDays(-1)]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $expiredAt = $this->unit('DUE-01')->expired_at;

        $this->artisan('inventory:expire-units')->assertSuccessful();

        // Idempotent because the first run left no available rows behind it,
        // not because the second run checks for its own work.
        $this->assertTrue($expiredAt->equalTo($this->unit('DUE-01')->expired_at));
        $this->assertSame(1, AuditLog::query()->where('action', 'inventory.expired')->count());
        $this->assertSame(0, $this->sweptRows()->last()->context['expired_count']);
    }

    public function test_it_reads_today_from_the_operational_timezone_not_the_ambient_clock(): void
    {
        // 17:00 UTC is already the next day in Manila. Under PHP's ambient
        // timezone — which phpunit.xml and a fresh clone both leave at UTC — the
        // sweep would compute 2026-08-25 and leave this unit on the shelf for
        // another eight hours.
        $this->travelTo(CarbonImmutable::parse('2026-08-25 17:00:00', 'UTC'));

        $this->makeUnit(['id' => 'DUE-01', 'expiry_date' => '2026-08-25']);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(BloodUnitStatus::Expired, $this->unit('DUE-01')->status);
        $this->assertSame('2026-08-26', $this->sweptRows()->sole()->context['operational_date']);
    }

    public function test_it_writes_one_audit_row_per_unit(): void
    {
        $this->makeUnit(['id' => 'DUE-01', 'expiry_date' => $this->inDays(-1)]);
        $this->makeUnit(['id' => 'DUE-02', 'expiry_date' => $this->inDays(-3)]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $rows = AuditLog::query()->where('action', 'inventory.expired')->get();

        // A count in a daily summary cannot answer "what happened to this bag",
        // which is the only question anyone asks the trail of a physical unit.
        $this->assertSame(2, $rows->count());
        $this->assertEqualsCanonicalizing(['DUE-01', 'DUE-02'], $rows->pluck('auditable_id')->all());
        $this->assertSame([BloodUnit::class, BloodUnit::class], $rows->pluck('auditable_type')->all());

        $row = $rows->firstWhere('auditable_id', 'DUE-01');

        // Nobody clicked anything, so source and run_id are what distinguish a
        // swept change from a staff action in a trail that is otherwise
        // anonymous.
        $this->assertNull($row->actor_id);
        $this->assertSame($this->facilityId, $row->context['facility_id']);
        $this->assertSame(OperationalDay::todayAsDate(), $row->context['operational_date']);
        $this->assertSame($this->inDays(-1), $row->context['expiry_date']);
        $this->assertSame(BloodUnitStatus::Available->value, $row->context['previous_status']);
        $this->assertSame('schedule:inventory:expire-units', $row->context['source']);
    }

    public function test_the_run_row_ties_every_unit_row_to_the_run_that_moved_it(): void
    {
        $this->makeUnit(['id' => 'DUE-01', 'expiry_date' => $this->inDays(-1)]);
        $this->makeUnit(['id' => 'DUE-02', 'expiry_date' => $this->inDays(-1)]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $swept = $this->sweptRows()->sole();
        $runIds = AuditLog::query()->where('action', 'inventory.expired')->get()
            ->map(fn (AuditLog $row): string => $row->context['run_id'])
            ->unique();

        $this->assertSame(2, $swept->context['expired_count']);
        $this->assertNull($swept->actor_id);
        $this->assertNull($swept->auditable_id);
        // One value, and it is the run's: a sweep is reconstructable in either
        // direction without joining on timestamps.
        $this->assertSame([$swept->context['run_id']], $runIds->values()->all());
    }

    public function test_a_run_that_expires_nothing_still_proves_it_ran(): void
    {
        $this->makeUnit(['id' => 'FUTURE-01', 'expiry_date' => $this->inDays(10)]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        // Otherwise a dead scheduler and a quiet day look identical in
        // audit_logs, and the absence of yesterday's row proves nothing.
        $swept = $this->sweptRows()->sole();

        $this->assertSame(0, $swept->context['expired_count']);
        $this->assertSame([], $swept->context['by_facility']);
    }

    public function test_it_counts_each_facility_against_itself(): void
    {
        $otherFacilityId = Facility::factory()->approved()->create()->id;

        $this->makeUnit(['id' => 'MINE-01', 'expiry_date' => $this->inDays(-1)]);
        $this->makeUnit(['id' => 'MINE-02', 'expiry_date' => $this->inDays(-1)]);
        $this->makeUnit(['id' => 'THEIRS-01', 'expiry_date' => $this->inDays(-1)], $otherFacilityId);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $swept = $this->sweptRows()->sole();

        // The sweep is the one system actor here that crosses facilities, so the
        // breakdown is what keeps a cross-centre run readable per centre.
        $this->assertSame(3, $swept->context['expired_count']);
        $this->assertSame(2, $swept->context['by_facility'][$this->facilityId]);
        $this->assertSame(1, $swept->context['by_facility'][$otherFacilityId]);

        $this->assertSame(
            $otherFacilityId,
            AuditLog::query()->where('auditable_id', 'THEIRS-01')->sole()->context['facility_id']
        );
    }

    public function test_a_second_chunk_is_audited_like_the_first(): void
    {
        // Past the command's 500-unit chunk, so the run spans two transactions
        // and a chunk that silently skipped its audit rows would show here.
        $this->seedDueUnits(501);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(501, AuditLog::query()->where('action', 'inventory.expired')->count());
        $this->assertSame(501, $this->sweptRows()->sole()->context['expired_count']);
        $this->assertSame(0, BloodUnit::query()->where('status', BloodUnitStatus::Available)->count());
    }

    /**
     * Seed a batch of past-expiry units against one donation.
     *
     * Inserted in bulk rather than through the factory: at this size the point
     * is the chunk boundary, not the individual rows, and a factory call per
     * unit would build a facility and a donation for each.
     */
    private function seedDueUnits(int $count): void
    {
        $donationId = Donation::factory()->create([
            'facility_id' => $this->facilityId,
            'donor_id' => $this->donorProfile->donor_id,
        ])->id;

        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => 'BULK-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'facility_id' => $this->facilityId,
                'component_id' => $this->component->id,
                'blood_type_id' => $this->bloodType->id,
                'donation_id' => $donationId,
                'expiry_date' => $this->inDays(-1),
                'status' => BloodUnitStatus::Available->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Chunked: sqlite binds every value in the statement, and one insert of
        // 501 rows exceeds its variable limit.
        foreach (array_chunk($rows, 100) as $chunk) {
            BloodUnit::insert($chunk);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeUnit(array $overrides = [], ?int $facilityId = null): BloodUnit
    {
        $facilityId ??= $this->facilityId;

        $donation = Donation::factory()->create([
            'facility_id' => $facilityId,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        return BloodUnit::factory()->create([
            'facility_id' => $facilityId,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            ...$overrides,
        ]);
    }

    private function unit(string $id): BloodUnit
    {
        return BloodUnit::query()->findOrFail($id);
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function sweptRows()
    {
        return AuditLog::query()->where('action', 'inventory.expiry_swept')->orderBy('id')->get();
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
