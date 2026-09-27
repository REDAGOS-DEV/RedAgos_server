<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The quarantine lifecycle: booked in held back, released only on both clearance tokens.
 *
 * Every refusal here is enforced in the service, not the gate, so each is also
 * asserted against a supervisor, who holds every ability.
 */
class QuarantineReleaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $officer;

    private User $supervisor;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->officer = User::factory()->bloodCenterStaff($this->facility, StaffRole::InventoryControlOfficer)->create();
        $this->supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Completed,
        ]);
    }

    private function held(int $count = 2, array $attributes = []): void
    {
        BloodUnit::factory()->count($count)->quarantined()->create([
            'facility_id' => $this->facility->id,
            'donation_id' => $this->donation->id,
            ...$attributes,
        ]);
    }

    private function clear(ClearanceKind ...$kinds): void
    {
        foreach ($kinds as $kind) {
            DonationClearance::create([
                'donation_id' => $this->donation->id,
                'kind' => $kind,
                'issued_at' => now(),
                'source' => 'test',
            ]);
        }
    }

    private function release(?User $as = null)
    {
        return $this->actingAs($as ?? $this->officer)
            ->postJson("/api/blood-center/inventory/quarantine/{$this->donation->id}/release");
    }

    private function statuses(): array
    {
        return BloodUnit::where('donation_id', $this->donation->id)->pluck('status')
            ->map(fn (BloodUnitStatus $status): string => $status->value)->unique()->values()->all();
    }

    public function test_both_tokens_release_every_held_unit(): void
    {
        $this->held(2);
        $this->clear(ClearanceKind::Tti, ClearanceKind::Immunohematology);

        $this->release()->assertOk()->assertJsonCount(2, 'units');

        $this->assertSame(['available'], $this->statuses());
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.released_from_quarantine', 'actor_id' => $this->officer->id]);
    }

    public function test_no_tokens_no_release_even_for_a_supervisor(): void
    {
        $this->held();

        foreach ([$this->officer, $this->supervisor] as $actor) {
            $this->release($actor)->assertStatus(409)->assertJsonPath('code', 'tti_not_cleared');
        }

        $this->assertSame(['quarantined'], $this->statuses());
    }

    public function test_one_token_is_not_enough(): void
    {
        $this->held();
        $this->clear(ClearanceKind::Tti);

        $this->release($this->supervisor)->assertStatus(409)->assertJsonPath('code', 'immunohematology_not_cleared');

        $this->assertSame(['quarantined'], $this->statuses());
    }

    public function test_a_rejected_donation_stays_locked_in_quarantine(): void
    {
        $this->held();
        $this->clear(ClearanceKind::Immunohematology);
        $this->donation->update(['status' => DonationStatus::Rejected]);

        $this->release($this->supervisor)->assertStatus(409)->assertJsonPath('code', 'donation_rejected');

        // Discard is the only way out.
        $unit = BloodUnit::where('donation_id', $this->donation->id)->first();

        $this->actingAs($this->officer)
            ->postJson("/api/blood-center/inventory/{$unit->id}/discard", ['reason' => 'Reactive donation'])
            ->assertOk();

        $this->assertSame(BloodUnitStatus::Discarded, $unit->fresh()->status);
    }

    public function test_the_payload_says_what_a_held_unit_is_waiting_for(): void
    {
        $this->held(1);
        $this->clear(ClearanceKind::Tti);

        $this->actingAs($this->officer)
            ->getJson('/api/blood-center/inventory?status=quarantined')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'quarantined')
            ->assertJsonPath('data.0.quarantine.tti', true)
            ->assertJsonPath('data.0.quarantine.immunohematology', false)
            ->assertJsonPath('data.0.quarantine.locked', false)
            ->assertJsonPath('data.0.quarantine.releasable', false);
    }

    public function test_a_unit_past_its_date_blocks_the_release_until_discarded(): void
    {
        $this->held(1);
        $this->held(1, ['expiry_date' => OperationalDay::today()->subDay()->toDateString()]);
        $this->clear(ClearanceKind::Tti, ClearanceKind::Immunohematology);

        $this->release()->assertStatus(409)->assertJsonPath('code', 'unit_past_expiry');

        $this->assertSame(['quarantined'], $this->statuses());
    }

    public function test_nothing_held_is_refused(): void
    {
        $this->clear(ClearanceKind::Tti, ClearanceKind::Immunohematology);

        $this->release()->assertStatus(409)->assertJsonPath('code', 'nothing_quarantined');
    }

    public function test_only_the_inventory_control_officer_holds_the_release(): void
    {
        $this->held();
        $this->clear(ClearanceKind::Tti, ClearanceKind::Immunohematology);

        foreach ([StaffRole::DispatchCoordinator, StaffRole::ItDataClerk, StaffRole::ComponentTechnologist, StaffRole::LabSupervisor] as $role) {
            $this->release(User::factory()->bloodCenterStaff($this->facility, $role)->create())->assertForbidden();
        }
    }

    public function test_an_edit_never_moves_a_unit_out_of_quarantine(): void
    {
        $this->held(1);
        $unit = BloodUnit::where('donation_id', $this->donation->id)->first();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/inventory/{$unit->id}", [
                'storage_location' => 'Quarantine Fridge 2',
                'expiry_date' => OperationalDay::today()->addDays(20)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('unit.status', 'quarantined');
    }

    public function test_the_expiry_sweep_leaves_quarantined_units_alone(): void
    {
        $this->held(1, ['expiry_date' => OperationalDay::today()->subDays(2)->toDateString()]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(['quarantined'], $this->statuses());
    }

    public function test_a_held_unit_is_not_stock(): void
    {
        $this->held(3);

        $this->actingAs($this->officer)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonPath('totals.quarantined', 3)
            ->assertJsonPath('totals.available', 0);
    }

    public function test_a_token_can_be_neither_changed_nor_withdrawn(): void
    {
        $this->clear(ClearanceKind::Tti);
        $token = DonationClearance::sole();

        try {
            $token->update(['source' => 'forged']);
            $this->fail('A token was edited.');
        } catch (LogicException) {
        }

        try {
            $token->delete();
            $this->fail('A token was withdrawn.');
        } catch (LogicException) {
        }

        $this->assertSame('test', DonationClearance::sole()->source);
    }

    public function test_a_token_is_revoked_once_and_a_revoked_token_releases_nothing(): void
    {
        $this->held(1);
        $this->clear(ClearanceKind::Tti, ClearanceKind::Immunohematology);

        $token = DonationClearance::where('kind', 'tti')->sole();
        $token->revoked_at = now();
        $token->revoked_by = $this->supervisor->id;
        $token->revoked_reason = 'Correction #1';
        $token->save();

        // Kept as history, but no longer in force.
        $this->assertSame(1, DonationClearance::where('kind', 'tti')->count());
        $this->release($this->supervisor)->assertStatus(409)->assertJsonPath('code', 'tti_not_cleared');

        // Revocation is final: it cannot be undone or rewritten.
        $this->expectException(LogicException::class);
        $token->revoked_at = null;
        $token->save();
    }

    public function test_dispatch_refuses_a_unit_whose_donation_was_never_cleared(): void
    {
        $bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $component = BloodComponent::factory()->create(['price' => 0]);
        $hospital = Facility::factory()->bloodBank()->approved()->create();
        $profile = DonorProfile::factory()->create(['donor_id' => User::factory()->create()->id, 'blood_type_id' => $bloodType->id]);

        $donation = Donation::factory()->create(['facility_id' => $this->facility->id, 'donor_id' => $profile->donor_id]);

        BloodUnit::factory()->create([
            'facility_id' => $this->facility->id,
            'blood_type_id' => $bloodType->id,
            'component_id' => $component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
        ]);

        $request = BloodRequest::factory()
            ->raisedBy($hospital, User::factory()->bloodBankStaff($hospital)->create())
            ->addressedTo($this->facility)
            ->forStock($bloodType, $component, 1)
            ->create();

        $this->actingAs($this->officer)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => 1])
            ->assertOk();

        // However it got onto the shelf, a unit whose donation holds no
        // tokens does not leave the building. Removed behind the model's back,
        // which is the only way a token can go.
        DB::table('donation_clearances')->where('donation_id', $donation->id)->delete();

        $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_cleared');
    }
}
