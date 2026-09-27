<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Enums\TestResult;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationComponent;
use App\Models\DonationTestResult;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RecordsTyping;
use Tests\TestCase;

/**
 * The last thing between an untested bag and a patient.
 *
 * `completed` is what blood-unit intake gates on, and this department is the
 * only place it can be written. Every guard here exists so a donation cannot
 * reach that status without a passing result and a declared yield.
 *
 * The Testing department's two sections themselves — ordering, authorship,
 * the reactive chain — are covered in TestingSectionsTest.
 */
class LaboratoryProcessingTest extends TestCase
{
    use LazilyRefreshDatabase, RecordsTyping;

    private Facility $facility;

    private User $testing;

    private User $typing;

    private User $processing;

    private BloodType $bloodType;

    private BloodComponent $component;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->testing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->typing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->processing = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();

        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        // donor() already creates the profile and its blood type, so the type
        // is read off the donor rather than forced -- BloodTypeFactory draws
        // from the same eight real codes, and inventing a ninth here collides
        // on the unique label.
        $donor = User::factory()->donor()->create();
        $profile = $donor->donorProfile;
        $this->bloodType = $profile->bloodType;

        // Handed over by Collection.
        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $profile->donor_id,
            'status' => DonationStatus::Collected,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordImmunohematology(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->typing)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/immunohematology",
            $this->concordantTyping($this->bloodType, $overrides)
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordSerology(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->testing)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/serology",
            [
                'hiv' => 'non_reactive',
                'hbsag' => 'non_reactive',
                'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive',
                'malaria' => 'non_reactive',
                ...$overrides,
            ]
        );
    }

    /**
     * Both Testing sections, all non-reactive: the donation passes to Processing.
     */
    private function recordPassingTests(): void
    {
        $this->recordImmunohematology()->assertCreated();
        $this->recordSerology()->assertCreated();
    }

    private function declareComponents(int $volumeMl = 250): TestResponse
    {
        return $this->actingAs($this->processing)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/components",
            ['components' => [['component_id' => $this->component->id, 'volume_ml' => $volumeMl]]]
        );
    }

    private function complete(): TestResponse
    {
        return $this->actingAs($this->processing)->patchJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/status",
            ['status' => 'completed']
        );
    }

    /**
     * A donation passed under the old single-result screen, before the sections existed.
     */
    private function legacyResult(TestResult $result): void
    {
        $this->donation->update(['status' => DonationStatus::Tested]);

        DonationTestResult::create([
            'donation_id' => $this->donation->id,
            'recorded_by' => $this->testing->id,
            'blood_type_id' => $this->bloodType->id,
            'result' => $result,
            'tested_at' => now()->subDay(),
        ]);
    }

    public function test_the_queue_shows_donations_handed_over_by_collection(): void
    {
        $ids = collect(
            $this->actingAs($this->testing)
                ->getJson('/api/blood-center/laboratory/queue')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertContains($this->donation->id, $ids);
    }

    public function test_both_clearances_move_the_donation_to_tested(): void
    {
        $this->recordImmunohematology()->assertCreated();

        // Saving a non-reactive panel is TTI Testing's clearance.
        $this->recordSerology()
            ->assertCreated()
            ->assertJsonPath('data.status', 'tested')
            ->assertJsonPath('data.clearances.tti', true);

        $this->assertSame(DonationStatus::Tested, $this->donation->fresh()->status);
        $this->assertSame('passed', DonationTestResult::where('donation_id', $this->donation->id)->sole()->result->value);
    }

    public function test_the_recording_staff_member_is_taken_from_the_token(): void
    {
        $this->recordPassingTests();

        $this->assertDatabaseHas('donation_serology', [
            'donation_id' => $this->donation->id,
            'recorded_by' => $this->testing->id,
        ]);
    }

    public function test_a_result_cannot_be_recorded_before_collection(): void
    {
        $this->donation->update(['status' => DonationStatus::Registered]);

        $this->recordImmunohematology()
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_collected');

        $this->recordSerology()
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_collected');
    }

    public function test_correcting_a_section_edits_the_same_rows(): void
    {
        // A held typing — the reverse grouping disagreed — is corrected in
        // place. Only a cleared typing is final.
        $discordant = $this->bloodType->code === 'O+' || $this->bloodType->code === 'O-' ? 'A' : 'O';

        $this->recordImmunohematology(['reverse_group' => $discordant])
            ->assertCreated()
            ->assertJsonPath('clearance_hold', 'abo_discrepancy');

        // Held, not cleared — but saved, so it changes only by an approved
        // correction, decided by the Reference Laboratory Consultant.
        $this->recordImmunohematology(['notes' => 'Re-read after centrifuge.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'correction_required');

        $correction = $this->actingAs($this->typing)
            ->postJson("/api/blood-center/donations/{$this->donation->id}/corrections", [
                'subject' => 'immunohematology',
                'reason' => 'Reverse grouping re-read after centrifuge.',
                'changes' => $this->concordantTyping($this->bloodType, ['notes' => 'Re-read after centrifuge.']),
            ])
            ->assertCreated()
            ->json('data.id');

        $consultant = User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create();

        $this->actingAs($consultant)
            ->postJson("/api/blood-center/corrections/{$correction}/approve")
            ->assertOk();

        $this->recordSerology()->assertCreated();

        // One of each per donation, so there is never an ambiguity about which
        // reading cleared the blood.
        $this->assertSame(1, DonationTestResult::where('donation_id', $this->donation->id)->count());
        $this->assertDatabaseCount('donation_immunohematology', 1);
        $this->assertDatabaseCount('donation_serology', 1);
    }

    public function test_a_typed_blood_type_contradicting_the_donor_record_is_refused(): void
    {
        $otherCode = $this->bloodType->code === 'AB-' ? 'O+' : 'AB-';
        $other = BloodType::firstOrCreate(['code' => $otherCode], ['label' => $otherCode]);

        // A person's blood type does not change, so a mismatch means one of the
        // two records is wrong — and blood_units derives its type from the
        // donor profile.
        $this->recordImmunohematology($this->concordantTyping($other))
            ->assertStatus(409)
            ->assertJsonPath('code', 'blood_type_mismatch');
    }

    public function test_a_passed_result_fills_in_a_donor_who_had_no_blood_type(): void
    {
        $profile = $this->donation->donorProfile;
        $profile->update(['blood_type_id' => null]);

        $this->recordPassingTests();

        // Without this a counter-registered walk-in is stuck: inventory derives
        // a unit's type from the profile and refuses the donation as
        // donor_blood_type_missing, so it could never become stock.
        $this->assertSame($this->bloodType->id, $profile->fresh()->blood_type_id);
    }

    public function test_a_cleared_typing_alone_fills_in_a_blood_type(): void
    {
        $profile = $this->donation->donorProfile;
        $profile->update(['blood_type_id' => null]);

        // Adopted when Immunohematology clears the typing, not when serology
        // comes back: a first-time donor's bags can then be booked into
        // quarantine while the serology run is still out.
        $this->recordImmunohematology()->assertCreated();

        $this->assertSame($this->bloodType->id, $profile->fresh()->blood_type_id);
    }

    public function test_a_held_typing_never_fills_in_a_blood_type(): void
    {
        $profile = $this->donation->donorProfile;
        $profile->update(['blood_type_id' => null]);

        $this->recordImmunohematology(['antibody_screen' => 'positive'])
            ->assertCreated()
            ->assertJsonPath('clearance_hold', 'antibody_screen_positive');

        $this->assertNull($profile->fresh()->blood_type_id);
    }

    public function test_filling_in_a_blood_type_is_audit_logged(): void
    {
        $this->donation->donorProfile->update(['blood_type_id' => null]);

        $this->recordPassingTests();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->typing->id,
            'action' => 'donor.blood_type_verified',
        ]);
    }

    public function test_a_reactive_result_with_no_typing_leaves_the_blood_type_blank(): void
    {
        $profile = $this->donation->donorProfile;
        $profile->update(['blood_type_id' => null]);

        $this->recordSerology(['hbsag' => 'reactive', 'confirm_reactive' => true])->assertCreated();

        $this->assertNull($profile->fresh()->blood_type_id);

        // And the rejected donation cannot be typed afterwards.
        $this->recordImmunohematology()->assertStatus(409)->assertJsonPath('code', 'results_locked');
    }

    public function test_a_blood_type_already_on_file_is_never_overwritten(): void
    {
        $profile = $this->donation->donorProfile;
        $original = $profile->blood_type_id;

        $this->recordPassingTests();

        $this->assertSame($original, $profile->fresh()->blood_type_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'donor.blood_type_verified']);
    }

    public function test_the_full_chain_clears_a_donation_for_issue(): void
    {
        $this->recordPassingTests();
        $this->declareComponents()->assertCreated();

        $this->complete()
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(DonationStatus::Completed, $this->donation->fresh()->status);
    }

    public function test_a_reactive_donation_can_never_be_cleared_for_issue(): void
    {
        $this->declareComponents()->assertCreated();
        $this->recordImmunohematology()->assertCreated();
        $this->recordSerology(['hiv' => 'reactive', 'confirm_reactive' => true])->assertCreated();

        // The rule the whole department exists for. A reactive panel rejects
        // the donation outright, so there is nothing left to clear.
        $this->complete()
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_already_final');

        $this->assertSame(DonationStatus::Rejected, $this->donation->fresh()->status);
    }

    public function test_a_cleared_section_can_no_longer_be_changed(): void
    {
        $this->recordPassingTests();

        // Both tokens are out. A token is what releases units, so the reading
        // it stands on cannot be rewritten underneath it.
        $this->recordImmunohematology()
            ->assertStatus(409)
            ->assertJsonPath('code', 'results_cleared');

        $this->recordSerology()
            ->assertStatus(409)
            ->assertJsonPath('code', 'results_cleared');
    }

    public function test_a_legacy_inconclusive_donation_never_earns_a_clearance(): void
    {
        $this->legacyResult(TestResult::Inconclusive);
        $this->declareComponents()->assertCreated();

        // Completing only hands the bags over. Nothing clears them.
        $this->complete()->assertOk();

        $this->assertSame(0, DonationClearance::where('donation_id', $this->donation->id)->count());
    }

    public function test_a_legacy_passed_donation_needs_serology_before_it_is_cleared(): void
    {
        $this->legacyResult(TestResult::Passed);
        $this->declareComponents()->assertCreated();
        $this->complete()->assertOk();

        // Every unit issued from now on has a reading for all five markers.
        $this->assertSame(0, DonationClearance::where('donation_id', $this->donation->id)->count());

        $this->recordPassingTests();

        $this->assertSame(2, DonationClearance::where('donation_id', $this->donation->id)->count());

        // Already completed, so it stays completed rather than stepping back.
        $this->assertSame(DonationStatus::Completed, $this->donation->fresh()->status);
    }

    public function test_a_legacy_passed_donation_is_back_in_the_testing_queue(): void
    {
        $this->legacyResult(TestResult::Passed);

        $ids = collect(
            $this->actingAs($this->testing)
                ->getJson('/api/blood-center/laboratory/queue?stage=testing')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertContains($this->donation->id, $ids);
    }

    public function test_processing_completes_without_waiting_for_results_but_clears_nothing(): void
    {
        // Plasma has to be frozen within hours; the serology run may take a
        // day. Completing hands the bags to Issuance, who book them in
        // quarantined — it no longer says anything about safety.
        $this->declareComponents()->assertCreated();

        $this->complete()->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(0, DonationClearance::where('donation_id', $this->donation->id)->count());
    }

    public function test_each_department_clears_only_its_own_section(): void
    {
        $this->recordImmunohematology()->assertCreated();

        $this->assertSame(
            ['immunohematology'],
            DonationClearance::where('donation_id', $this->donation->id)->pluck('kind')->map->value->all()
        );
        $this->assertSame(DonationStatus::Collected, $this->donation->fresh()->status);
    }

    public function test_a_donation_cannot_be_cleared_without_a_declared_yield(): void
    {
        $this->recordPassingTests();

        $this->complete()
            ->assertStatus(409)
            ->assertJsonPath('code', 'components_missing');
    }

    public function test_components_can_be_declared_before_a_result(): void
    {
        // Testing and processing are worked in parallel at the bench, so
        // neither recording waits on the other.
        $this->declareComponents()->assertCreated();

        $this->assertSame(DonationStatus::Collected, $this->donation->fresh()->status);
    }

    public function test_components_declared_first_still_do_not_clear_a_donation(): void
    {
        $this->declareComponents()->assertCreated();

        // The two branches no longer join at completion: they join at the
        // quarantine release, which needs both tokens.
        $this->complete()->assertOk();

        $this->assertSame(DonationStatus::Completed, $this->donation->fresh()->status);
        $this->assertSame(0, DonationClearance::where('donation_id', $this->donation->id)->count());
    }

    public function test_components_cannot_be_declared_before_the_counter_has_finished(): void
    {
        $this->donation->update(['status' => DonationStatus::Screening]);

        $this->declareComponents()
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_collected');
    }

    public function test_two_bags_of_one_component_are_two_rows_with_their_own_volumes(): void
    {
        $this->recordPassingTests();

        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/components", [
                'components' => [
                    ['component_id' => $this->component->id, 'volume_ml' => 250],
                    ['component_id' => $this->component->id, 'volume_ml' => 230],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.components')
            ->assertJsonPath('data.components.0.volume_ml', 250)
            ->assertJsonPath('data.components.1.volume_ml', 230);

        // One bag each: inventory may book in exactly two units of it.
        $this->assertSame(2, (int) DonationComponent::where('donation_id', $this->donation->id)->sum('quantity'));
    }

    public function test_every_bag_needs_a_volume(): void
    {
        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/components", [
                'components' => [
                    ['component_id' => $this->component->id],
                    ['component_id' => $this->component->id, 'volume_ml' => 0],
                    ['component_id' => $this->component->id, 'volume_ml' => 5000],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['components.0.volume_ml', 'components.1.volume_ml', 'components.2.volume_ml']);
    }

    public function test_a_quantity_is_no_longer_accepted_in_place_of_a_volume(): void
    {
        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/components", [
                'components' => [['component_id' => $this->component->id, 'quantity' => 2]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('components.0.volume_ml');
    }

    public function test_processing_may_still_reject_a_donation_by_hand(): void
    {
        $this->recordPassingTests();

        $this->actingAs($this->processing)
            ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Clotted during separation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_the_laboratory_cannot_set_a_collection_status(): void
    {
        foreach (['registered', 'screening', 'collected', 'tested'] as $status) {
            $this->actingAs($this->processing)
                ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", ['status' => $status])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }
    }

    public function test_another_facilitys_donation_is_not_found(): void
    {
        $foreign = Donation::factory()->create([
            'facility_id' => Facility::factory()->approved()->create()->id,
            'donor_id' => $this->donation->donor_id,
            'status' => DonationStatus::Collected,
        ]);

        $this->actingAs($this->typing)
            ->postJson("/api/blood-center/laboratory/donations/{$foreign->id}/immunohematology", $this->concordantTyping($this->bloodType))
            ->assertNotFound();
    }

    public function test_the_old_single_result_route_is_gone(): void
    {
        // Nothing may pass a donation without all five markers recorded.
        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/results", [
                'result' => 'passed',
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertNotFound();
    }

    public function test_collection_staff_cannot_reach_the_laboratory(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();

        $this->actingAs($collection)->getJson('/api/blood-center/laboratory/queue')->assertForbidden();

        $this->actingAs($collection)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/immunohematology", [
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertForbidden();
    }

    public function test_issuance_staff_cannot_clear_a_donation_for_issue(): void
    {
        $issuance = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();

        $this->actingAs($issuance)
            ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", ['status' => 'completed'])
            ->assertForbidden();
    }

    public function test_testing_and_processing_both_read_the_queue(): void
    {
        $this->actingAs($this->testing)->getJson('/api/blood-center/laboratory/queue')->assertOk();
        $this->actingAs($this->processing)->getJson('/api/blood-center/laboratory/queue')->assertOk();

        $this->actingAs($this->processing)
            ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
            ->assertOk();
    }

    public function test_testing_staff_cannot_declare_components_or_clear_a_donation(): void
    {
        $this->recordPassingTests();

        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/components", [
                'components' => [['component_id' => $this->component->id, 'volume_ml' => 250]],
            ])
            ->assertForbidden();

        $this->declareComponents()->assertCreated();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", ['status' => 'completed'])
            ->assertForbidden();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Clotted',
            ])
            ->assertForbidden();
    }

    public function test_processing_staff_cannot_record_a_test_section(): void
    {
        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/immunohematology", [
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertForbidden();

        $this->actingAs($this->processing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/serology", [
                'hiv' => 'non_reactive',
                'hbsag' => 'non_reactive',
                'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive',
                'malaria' => 'non_reactive',
            ])
            ->assertForbidden();
    }
}
