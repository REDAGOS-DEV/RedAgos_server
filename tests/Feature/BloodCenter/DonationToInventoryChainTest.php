<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Donor at the counter to issuable stock, across three departments.
 *
 * Until Laboratory existed nothing wrote `completed`, so the finished
 * inventory module was unreachable: no code path could produce a donation it
 * would accept. This is the proof that the chain now closes, and that each
 * department can only do its own part of it.
 */
class DonationToInventoryChainTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $collection;

    private User $testing;

    private User $processing;

    private User $inventory;

    private User $donor;

    private BloodComponent $packedRbc;

    private BloodComponent $plasma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->collection = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();
        $this->testing = User::factory()->bloodCenterStaff($this->facility, Department::Testing)->create();
        $this->processing = User::factory()->bloodCenterStaff($this->facility, Department::Processing)->create();
        $this->inventory = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();

        $this->donor = User::factory()->donor()->create();
        $this->packedRbc = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->plasma = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);
    }

    public function test_a_donation_travels_from_the_counter_to_issuable_stock(): void
    {
        // --- Collection -----------------------------------------------
        $donationId = $this->actingAs($this->collection)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$donationId}/screening", ['outcome' => 'accepted'])
            ->assertCreated();

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$donationId}/collection", $this->collectionPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected');

        // --- Testing and Processing ---------------------------------------
        $bloodTypeId = $this->donor->donorProfile->blood_type_id;

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/immunohematology", [
                'blood_type_id' => $bloodTypeId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected');

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/serology", $this->nonReactivePanel())
            ->assertCreated()
            ->assertJsonPath('data.status', 'tested');

        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/components", [
                'components' => [
                    ['component_id' => $this->packedRbc->id, 'volume_ml' => 250],
                    ['component_id' => $this->plasma->id, 'volume_ml' => 220],
                ],
            ])
            ->assertCreated();

        $this->actingAs($this->processing)
            ->patchJson("/api/blood-center/laboratory/donations/{$donationId}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // --- Issuance -----------------------------------------------------
        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', [
                'donation_id' => $donationId,
                'units' => [
                    ['component_id' => $this->packedRbc->id, 'expiry_date' => now()->addDays(35)->toDateString()],
                    ['component_id' => $this->plasma->id, 'expiry_date' => now()->addDays(365)->toDateString()],
                ],
            ])
            ->assertCreated();

        $units = BloodUnit::where('donation_id', $donationId)->get();

        $this->assertCount(2, $units);
        $this->assertTrue($units->every(fn (BloodUnit $u): bool => $u->status === BloodUnitStatus::Available));

        // Derived from the donor, never sent by any of the three departments.
        $this->assertTrue($units->every(fn (BloodUnit $u): bool => (int) $u->blood_type_id === (int) $bloodTypeId));

        // Each unit carries the volume Processing recorded for its bag.
        $this->assertSame(250, $units->firstWhere('component_id', $this->packedRbc->id)->volume_ml);
        $this->assertSame(220, $units->firstWhere('component_id', $this->plasma->id)->volume_ml);

        // And it now shows up as issuable stock.
        $this->actingAs($this->inventory)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonPath('totals.available', 2);
    }

    public function test_two_bags_of_one_component_book_in_as_two_units_in_order(): void
    {
        $donationId = $this->completedDonationDeclaring([
            ['component_id' => $this->packedRbc->id, 'volume_ml' => 250],
            ['component_id' => $this->packedRbc->id, 'volume_ml' => 230],
        ]);

        $unit = ['component_id' => $this->packedRbc->id, 'expiry_date' => now()->addDays(35)->toDateString()];

        // Separate intakes: the second unit must take the second bag.
        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', ['donation_id' => $donationId, 'units' => [$unit]])
            ->assertCreated()
            ->assertJsonPath('units.0.volume_ml', 250);

        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', ['donation_id' => $donationId, 'units' => [$unit]])
            ->assertCreated()
            ->assertJsonPath('units.0.volume_ml', 230);

        // And no third.
        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', ['donation_id' => $donationId, 'units' => [$unit]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exceeds_declared_quantity');
    }

    public function test_the_intake_queue_shows_each_bag_still_to_shelve(): void
    {
        $donationId = $this->completedDonationDeclaring([
            ['component_id' => $this->packedRbc->id, 'volume_ml' => 250],
            ['component_id' => $this->packedRbc->id, 'volume_ml' => 230],
            ['component_id' => $this->plasma->id, 'volume_ml' => 220],
        ]);

        $row = collect(
            $this->actingAs($this->inventory)->getJson('/api/blood-center/inventory/intake-queue')->assertOk()->json('data')
        )->firstWhere('donation_id', $donationId);

        $packed = collect($row['components'])->firstWhere('component_id', $this->packedRbc->id);

        $this->assertSame(2, $packed['declared']);
        $this->assertSame([250, 230], $packed['volumes']);
        $this->assertSame([250, 230], $packed['outstanding_volumes']);
        $this->assertSame(3, $row['declared_units']);
    }

    public function test_inventory_cannot_book_in_a_component_the_laboratory_never_declared(): void
    {
        $donationId = $this->completedDonationDeclaring([
            ['component_id' => $this->packedRbc->id, 'volume_ml' => 250],
        ]);

        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', [
                'donation_id' => $donationId,
                'units' => [
                    ['component_id' => $this->plasma->id, 'expiry_date' => now()->addDays(365)->toDateString()],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('units.0.component_id');

        $this->assertSame(0, BloodUnit::where('donation_id', $donationId)->count());
    }

    public function test_inventory_cannot_exceed_the_declared_quantity(): void
    {
        $donationId = $this->completedDonationDeclaring([
            ['component_id' => $this->packedRbc->id, 'volume_ml' => 250],
        ]);

        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', [
                'donation_id' => $donationId,
                'units' => [
                    ['component_id' => $this->packedRbc->id, 'expiry_date' => now()->addDays(35)->toDateString()],
                    ['component_id' => $this->packedRbc->id, 'expiry_date' => now()->addDays(35)->toDateString()],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exceeds_declared_quantity');
    }

    public function test_the_declared_limit_holds_across_separate_intakes(): void
    {
        $donationId = $this->completedDonationDeclaring([
            ['component_id' => $this->packedRbc->id, 'volume_ml' => 250],
        ]);

        $unit = ['component_id' => $this->packedRbc->id, 'expiry_date' => now()->addDays(35)->toDateString()];

        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', ['donation_id' => $donationId, 'units' => [$unit]])
            ->assertCreated();

        // The second intake is a separate request, so the guard has to count
        // what already exists rather than only what this payload asks for.
        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', ['donation_id' => $donationId, 'units' => [$unit]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exceeds_declared_quantity');

        $this->assertSame(1, BloodUnit::where('donation_id', $donationId)->count());
    }

    public function test_a_reactive_donation_never_becomes_stock(): void
    {
        $donationId = $this->collectedDonation();

        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/components", [
                'components' => [['component_id' => $this->packedRbc->id, 'volume_ml' => 250]],
            ])
            ->assertCreated();

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/serology", [
                ...$this->nonReactivePanel(),
                'hcv' => 'reactive',
                'confirm_reactive' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'rejected');

        // Cannot be cleared: a reactive panel rejects it on the spot...
        $this->actingAs($this->processing)
            ->patchJson("/api/blood-center/laboratory/donations/{$donationId}/status", ['status' => 'completed'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_already_final');

        // ...and therefore cannot enter stock. This is the whole point of the
        // intake gate: reactive blood has no route to a patient.
        $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', [
                'donation_id' => $donationId,
                'units' => [['component_id' => $this->packedRbc->id, 'expiry_date' => now()->addDays(35)->toDateString()]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_completed');

        $this->assertSame(0, BloodUnit::where('donation_id', $donationId)->count());
    }

    /**
     * Walk a donation to `collected` through the real endpoints.
     */
    private function collectedDonation(): int
    {
        $id = $this->actingAs($this->collection)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->json('data.id');

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'accepted']);

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload());

        return $id;
    }

    private int $segments = 0;

    /**
     * A complete "For Phlebotomist Use Only" box, with a fresh segment number each call.
     *
     * @return array<string, mixed>
     */
    private function collectionPayload(): array
    {
        $this->segments++;

        return [
            'volume_ml' => 450,
            'blood_bag_type' => 'double',
            'segment_number' => 'SEG-'.$this->segments,
            'started_at' => now()->subMinutes(15)->toISOString(),
            'ended_at' => now()->subMinutes(5)->toISOString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function nonReactivePanel(): array
    {
        return [
            'hiv' => 'non_reactive',
            'hbsag' => 'non_reactive',
            'hcv' => 'non_reactive',
            'syphilis' => 'non_reactive',
            'malaria' => 'non_reactive',
        ];
    }

    /**
     * Walk a donation all the way to `completed` with the given declaration.
     *
     * @param  array<int, array{component_id: int, volume_ml: int}>  $components
     */
    private function completedDonationDeclaring(array $components): int
    {
        $id = $this->collectedDonation();

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/immunohematology", [
                'blood_type_id' => $this->donor->donorProfile->blood_type_id,
            ]);

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/serology", $this->nonReactivePanel());

        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/components", ['components' => $components]);

        $this->actingAs($this->processing)
            ->patchJson("/api/blood-center/laboratory/donations/{$id}/status", ['status' => 'completed']);

        return $id;
    }
}
