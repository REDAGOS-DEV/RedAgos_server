<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\RecordsTyping;
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
    use LazilyRefreshDatabase, RecordsTyping;

    private Facility $facility;

    private User $receptionist;

    private User $physician;

    private User $collection;

    private User $typing;

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
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->collection = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();
        $this->typing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->testing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->processing = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();
        $this->inventory = User::factory()->bloodCenterStaff($this->facility, StaffRole::InventoryControlOfficer)->create();

        $this->donor = User::factory()->donor()->create();
        $this->packedRbc = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->plasma = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);
    }

    public function test_a_donation_travels_from_the_counter_to_issuable_stock(): void
    {
        // --- Collection -----------------------------------------------
        $donationId = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$donationId}/screening", ['outcome' => 'accepted'])
            ->assertCreated();

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$donationId}/collection", $this->collectionPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected');

        // --- Testing and Processing ---------------------------------------
        $bloodTypeId = $this->donor->donorProfile->blood_type_id;

        $this->actingAs($this->typing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/immunohematology", $this->concordantTyping($bloodTypeId))
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected');

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/serology", $this->nonReactivePanel())
            ->assertCreated()
            ->assertJsonPath('data.status', 'tested');

        $this->assertSame(DonationStatus::Tested, Donation::findOrFail($donationId)->status);

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

        // Booked in held back, and released on the Inventory Control
        // Officer's act once testing has cleared the donation.
        $this->assertTrue($units->every(fn (BloodUnit $u): bool => $u->status === BloodUnitStatus::Quarantined));

        $this->actingAs($this->inventory)
            ->postJson("/api/blood-center/inventory/quarantine/{$donationId}/release")
            ->assertOk();

        $units = BloodUnit::where('donation_id', $donationId)->get();

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
        $id = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->json('data.id');

        $this->actingAs($this->physician)
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
    /**
     * Every role doing exactly its own part, from the counter to the hospital.
     *
     * Nobody here holds more than their role grants, and the donor's name
     * never reaches a laboratory or inventory role along the way.
     */
    public function test_every_role_plays_its_part_from_the_counter_to_the_hospital(): void
    {
        $clerk = User::factory()->bloodCenterStaff($this->facility, StaffRole::ItDataClerk)->create();
        $dispatch = User::factory()->bloodCenterStaff($this->facility, StaffRole::DispatchCoordinator)->create();

        // Counter: receptionist opens, physician screens, phlebotomist draws.
        $donationId = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$donationId}/screening", ['outcome' => 'accepted'])
            ->assertCreated();

        $this->actingAs($this->collection)
            ->postJson("/api/blood-center/donations/{$donationId}/collection", $this->collectionPayload())
            ->assertCreated();

        // Processing does not wait for the laboratory.
        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/components", [
                'components' => [['component_id' => $this->packedRbc->id, 'volume_ml' => 250]],
            ])->assertCreated();

        $this->actingAs($this->processing)
            ->patchJson("/api/blood-center/laboratory/donations/{$donationId}/status", ['status' => 'completed'])
            ->assertOk();

        // Issuance books the bag in, held back.
        $unitId = $this->actingAs($this->inventory)
            ->postJson('/api/blood-center/inventory', [
                'donation_id' => $donationId,
                'units' => [['component_id' => $this->packedRbc->id, 'expiry_date' => now()->addDays(35)->toDateString()]],
            ])
            ->assertCreated()
            ->assertJsonPath('units.0.status', 'quarantined')
            ->json('units.0.id');

        $this->actingAs($this->inventory)
            ->postJson("/api/blood-center/inventory/quarantine/{$donationId}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'tti_not_cleared');

        // Immunohematology types the unit, blind.
        $this->actingAs($this->typing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/immunohematology", $this->concordantTyping($this->donor->donorProfile->blood_type_id))
            ->assertCreated()
            ->assertJsonPath('data.donor.blinded', true);

        // TTI Testing reads the panel, blind; saving it clears it.
        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$donationId}/serology", $this->nonReactivePanel())
            ->assertCreated()
            ->assertJsonPath('data.donor.blinded', true);

        // Issuance releases it from quarantine.
        $this->actingAs($this->inventory)
            ->postJson("/api/blood-center/inventory/quarantine/{$donationId}/release")
            ->assertOk();

        $this->assertSame(BloodUnitStatus::Available, BloodUnit::findOrFail($unitId)->status);

        // A hospital asks; the Inventory Control Officer reserves the bag; dispatch sends it.
        $hospital = Facility::factory()->bloodBank()->approved()->create();
        $request = BloodRequest::factory()
            ->raisedBy($hospital, User::factory()->bloodBankStaff($hospital)->create())
            ->addressedTo($this->facility)
            ->forStock($this->donor->donorProfile->bloodType, $this->packedRbc, 1)
            ->create();

        $this->actingAs($dispatch)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => 1])
            ->assertForbidden();

        $this->actingAs($this->inventory)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => 1])
            ->assertOk();

        // The IT clerk reads stock and nothing more.
        $this->actingAs($clerk)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertForbidden();

        $this->actingAs($dispatch)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->assertSame(BloodUnitStatus::Issued, BloodUnit::findOrFail($unitId)->status);
    }

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

        $this->actingAs($this->typing)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/immunohematology", $this->concordantTyping($this->donor->donorProfile->blood_type_id));

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/serology", $this->nonReactivePanel());

        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$id}/components", ['components' => $components]);

        $this->actingAs($this->processing)
            ->patchJson("/api/blood-center/laboratory/donations/{$id}/status", ['status' => 'completed']);

        return $id;
    }
}
