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

    private User $lab;

    private User $inventory;

    private User $donor;

    private BloodComponent $packedRbc;

    private BloodComponent $plasma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->collection = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();
        $this->lab = User::factory()->bloodCenterStaff($this->facility, Department::Laboratory)->create();
        $this->inventory = User::factory()->bloodCenterStaff($this->facility, Department::Inventory)->create();

        $this->donor = User::factory()->donor()->create();
        $this->packedRbc = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->plasma = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);
    }

    public function test_a_donation_travels_from_the_counter_to_issuable_stock(): void
    {
        // --- Donor/Collection -------------------------------------------
        $donationId = $this->actingAs($this->collection)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->collection)
            ->patchJson("/api/blood-center/donations/{$donationId}/status", ['status' => 'screening'])
            ->assertOk();

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$donationId}/collection", ['volume_ml' => 450])
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected');

        // --- Laboratory/Processing ---------------------------------------
        $bloodTypeId = $this->donor->donorProfile->blood_type_id;

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/results", [
                'result' => 'passed',
                'blood_type_id' => $bloodTypeId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'tested');

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/components", [
                'components' => [
                    ['component_id' => $this->packedRbc->id, 'quantity' => 1],
                    ['component_id' => $this->plasma->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this->actingAs($this->lab)
            ->patchJson("/api/blood-center/laboratory/donations/{$donationId}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // --- Inventory/Storage --------------------------------------------
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

        // And it now shows up as issuable stock.
        $this->actingAs($this->inventory)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonPath('totals.available', 2);
    }

    public function test_inventory_cannot_book_in_a_component_the_laboratory_never_declared(): void
    {
        $donationId = $this->completedDonationDeclaring([
            ['component_id' => $this->packedRbc->id, 'quantity' => 1],
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
            ['component_id' => $this->packedRbc->id, 'quantity' => 1],
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
            ['component_id' => $this->packedRbc->id, 'quantity' => 1],
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

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/results", [
                'result' => 'reactive',
                'blood_type_id' => $this->donor->donorProfile->blood_type_id,
            ])
            ->assertCreated();

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/components", [
                'components' => [['component_id' => $this->packedRbc->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        // Cannot be cleared...
        $this->actingAs($this->lab)
            ->patchJson("/api/blood-center/laboratory/donations/{$donationId}/status", ['status' => 'completed'])
            ->assertStatus(422);

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
            ->patchJson("/api/blood-center/donations/{$id}/status", ['status' => 'screening']);

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$id}/collection", ['volume_ml' => 450]);

        return $id;
    }

    /**
     * Walk a donation all the way to `completed` with the given declaration.
     *
     * @param  array<int, array{component_id: int, quantity: int}>  $components
     */
    private function completedDonationDeclaring(array $components): int
    {
        $id = $this->collectedDonation();

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/results", [
                'result' => 'passed',
                'blood_type_id' => $this->donor->donorProfile->blood_type_id,
            ]);

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/components", ['components' => $components]);

        $this->actingAs($this->lab)
            ->patchJson("/api/blood-center/laboratory/donations/{$id}/status", ['status' => 'completed']);

        return $id;
    }
}
