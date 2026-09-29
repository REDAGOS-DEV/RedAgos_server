<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodCollection;
use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A saved record changes only through a request someone else approves.
 */
class CorrectionRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Collected,
        ]);

        BloodCollection::where('donation_id', $this->donation->id)->update([
            'donation_barcode' => 'SEG-1001',
            'facility_id' => $this->facility->id,
        ]);
    }

    private function staff(StaffRole $role): User
    {
        return User::factory()->bloodCenterStaff($this->facility, $role)->create();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function ask(User $as, string $subject, array $changes, ?Donation $donation = null): TestResponse
    {
        return $this->actingAs($as)->postJson('/api/blood-center/donations/'.($donation ?? $this->donation)->id.'/corrections', [
            'subject' => $subject,
            'reason' => 'Entered wrongly at the bench.',
            'changes' => $changes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionBox(string $segment = 'SEG-1010'): array
    {
        return [
            'volume_ml' => 460,
            'blood_bag_type' => 'triple',
            'donation_barcode' => $segment,
            'started_at' => now()->subMinutes(20)->toISOString(),
            'ended_at' => now()->subMinutes(8)->toISOString(),
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function panel(array $overrides = []): array
    {
        return [
            'hiv' => 'non_reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive',
            'syphilis' => 'non_reactive', 'malaria' => 'non_reactive',
            ...$overrides,
        ];
    }

    private function recordSerology(User $as, array $overrides = [], ?Donation $donation = null): TestResponse
    {
        return $this->actingAs($as)
            ->postJson('/api/blood-center/laboratory/donations/'.($donation ?? $this->donation)->id.'/serology', $this->panel($overrides));
    }

    public function test_the_chair_corrects_a_collection_box_on_the_physicians_approval(): void
    {
        $phlebotomist = $this->staff(StaffRole::Phlebotomist);
        $physician = $this->staff(StaffRole::ScreeningPhysician);

        $id = $this->ask($phlebotomist, 'collection', $this->collectionBox())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.donation_barcode', 'SEG-1001')
            ->json('data.id');

        // Nothing changes until someone decides.
        $this->assertSame('SEG-1001', BloodCollection::where('donation_id', $this->donation->id)->value('donation_barcode'));

        $this->actingAs($physician)
            ->postJson("/api/blood-center/corrections/{$id}/approve", ['note' => 'Checked against the bag.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $collection = BloodCollection::where('donation_id', $this->donation->id)->sole();

        $this->assertSame('SEG-1010', $collection->donation_barcode);
        $this->assertSame(460, $this->donation->fresh()->volume_ml);
        $this->assertDatabaseHas('audit_logs', ['action' => 'correction.applied']);
    }

    public function test_a_correction_may_keep_its_own_donation_barcode(): void
    {
        $this->ask($this->staff(StaffRole::Phlebotomist), 'collection', $this->collectionBox('SEG-1001'))
            ->assertCreated();
    }

    public function test_a_correction_filed_under_the_old_segment_number_key_still_applies(): void
    {
        $id = $this->ask($this->staff(StaffRole::Phlebotomist), 'collection', $this->collectionBox())
            ->assertCreated()
            ->json('data.id');

        // As a request filed before the rename would have stored it.
        $correction = CorrectionRequest::findOrFail($id);
        $changes = $correction->changes;
        $changes['segment_number'] = $changes['donation_barcode'];
        unset($changes['donation_barcode']);
        $correction->forceFill(['changes' => $changes])->save();

        $this->actingAs($this->staff(StaffRole::ScreeningPhysician))
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertOk();

        $this->assertSame('SEG-1010', BloodCollection::where('donation_id', $this->donation->id)->value('donation_barcode'));
    }

    public function test_the_corrected_values_must_pass_the_original_rules(): void
    {
        $this->ask($this->staff(StaffRole::Phlebotomist), 'collection', [...$this->collectionBox(), 'volume_ml' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('changes.volume_ml');
    }

    public function test_nobody_approves_their_own_request(): void
    {
        $supervisor = $this->staff(StaffRole::LabSupervisor);
        $this->recordSerology($supervisor)->assertCreated();

        $id = $this->ask($supervisor, 'serology', $this->panel(['hiv' => 'reactive', 'confirm_reactive' => true]))->assertCreated()->json('data.id');

        $this->actingAs($supervisor)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'self_approval');

        // Nor does a Center Admin.
        $admin = User::factory()->bloodCenterSupervisor($this->facility)->create();
        $other = Donation::factory()->create(['facility_id' => $this->facility->id, 'status' => DonationStatus::Collected]);

        $this->actingAs($admin)
            ->postJson("/api/blood-center/laboratory/donations/{$other->id}/serology", $this->panel())
            ->assertCreated();

        $own = $this->ask($admin, 'serology', $this->panel(['hcv' => 'reactive', 'confirm_reactive' => true]), $other)->assertCreated()->json('data.id');

        $this->actingAs($admin)
            ->postJson("/api/blood-center/corrections/{$own}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'self_approval');
    }

    public function test_an_approvers_own_request_goes_to_the_center_admin_not_a_peer(): void
    {
        $supervisor = $this->staff(StaffRole::LabSupervisor);
        $peer = $this->staff(StaffRole::LabSupervisor);
        $this->recordSerology($supervisor)->assertCreated();

        $id = $this->ask($supervisor, 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))->assertCreated()->json('data.id');

        $this->actingAs($peer)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('code', 'not_the_approver');

        $this->actingAs(User::factory()->bloodCenterSupervisor($this->facility)->create())
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertOk();
    }

    public function test_an_approver_from_another_department_is_refused(): void
    {
        $technologist = $this->staff(StaffRole::SerologyTechnologist);
        $this->recordSerology($technologist)->assertCreated();

        $id = $this->ask($technologist, 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))->assertCreated()->json('data.id');

        foreach ([StaffRole::ScreeningPhysician, StaffRole::ComponentTechnologist] as $role) {
            $this->actingAs($this->staff($role))
                ->postJson("/api/blood-center/corrections/{$id}/approve")
                ->assertForbidden();
        }

        $this->actingAs($this->staff(StaffRole::LabSupervisor))
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertOk();
    }

    public function test_only_the_role_that_writes_a_record_may_ask_to_correct_it(): void
    {
        $this->recordSerology($this->staff(StaffRole::SerologyTechnologist))->assertCreated();

        $this->ask($this->staff(StaffRole::ComponentTechnologist), 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))
            ->assertForbidden()
            ->assertJsonPath('code', 'not_your_record');

        // And roles outside the laboratory cannot reach the route at all.
        $this->ask($this->staff(StaffRole::DispatchCoordinator), 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))
            ->assertForbidden();
    }

    public function test_a_reactive_result_can_never_be_corrected(): void
    {
        $technologist = $this->staff(StaffRole::SerologyTechnologist);
        $this->recordSerology($technologist, ['hiv' => 'reactive', 'confirm_reactive' => true])->assertCreated();

        $this->ask($technologist, 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_correctable');
    }

    public function test_a_cleared_result_is_corrected_while_its_bags_are_in_quarantine(): void
    {
        $technologist = $this->staff(StaffRole::SerologyTechnologist);
        $this->recordSerology($technologist)->assertCreated();

        // Saving the non-reactive panel cleared it at once.
        $this->assertTrue(DonationClearance::where('donation_id', $this->donation->id)->where('kind', 'tti')->exists());

        $id = $this->ask($technologist, 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->staff(StaffRole::LabSupervisor))
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertOk();

        // The old clearance is revoked, kept as history, and not replaced:
        // the corrected panel is reactive, and it rejected the donation.
        $token = DonationClearance::where('donation_id', $this->donation->id)->where('kind', 'tti')->sole();
        $this->assertNotNull($token->revoked_at);
        $this->assertSame(DonationStatus::Rejected, $this->donation->fresh()->status);
    }

    public function test_a_correction_that_still_clears_reissues_the_clearance(): void
    {
        $typist = $this->staff(StaffRole::SerologyTechnologist);
        $bloodType = $this->donation->donorProfile->bloodType;
        $group = rtrim($bloodType->code, '+-');
        $typing = [
            'blood_type_id' => $bloodType->id,
            'forward_group' => $group,
            'reverse_group' => $group,
            'antibody_screen' => 'negative',
        ];

        $this->actingAs($typist)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/immunohematology", $typing)
            ->assertCreated();

        $id = $this->ask($typist, 'immunohematology', [...$typing, 'notes' => 'Re-read.'])->assertCreated()->json('data.id');

        $this->actingAs($this->staff(StaffRole::LabSupervisor))
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertOk();

        $tokens = DonationClearance::where('donation_id', $this->donation->id)->where('kind', 'immunohematology')->get();

        $this->assertCount(2, $tokens);
        $this->assertCount(1, $tokens->whereNull('revoked_at'));
    }

    public function test_a_cleared_result_is_final_once_a_bag_has_left_quarantine(): void
    {
        $technologist = $this->staff(StaffRole::SerologyTechnologist);
        $this->recordSerology($technologist)->assertCreated();

        BloodUnit::factory()->create([
            'facility_id' => $this->facility->id,
            'donation_id' => $this->donation->id,
            'status' => BloodUnitStatus::Available,
        ]);

        $this->ask($technologist, 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'units_released');
    }

    public function test_one_pending_request_per_record(): void
    {
        $phlebotomist = $this->staff(StaffRole::Phlebotomist);

        $this->ask($phlebotomist, 'collection', $this->collectionBox())->assertCreated();
        $this->ask($phlebotomist, 'collection', $this->collectionBox('SEG-1011'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'correction_pending');
    }

    public function test_nothing_recorded_means_nothing_to_correct(): void
    {
        $this->ask($this->staff(StaffRole::SerologyTechnologist), 'serology', $this->panel())
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_correct');
    }

    public function test_a_rejection_needs_a_reason_and_changes_nothing(): void
    {
        $id = $this->ask($this->staff(StaffRole::Phlebotomist), 'collection', $this->collectionBox())->json('data.id');
        $physician = $this->staff(StaffRole::ScreeningPhysician);

        $this->actingAs($physician)
            ->postJson("/api/blood-center/corrections/{$id}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->actingAs($physician)
            ->postJson("/api/blood-center/corrections/{$id}/reject", ['note' => 'The bag says SEG-1001.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame('SEG-1001', BloodCollection::where('donation_id', $this->donation->id)->value('donation_barcode'));

        $this->actingAs($physician)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'correction_decided');
    }

    public function test_an_approval_a_guard_refuses_applies_nothing_and_stays_pending(): void
    {
        $technologist = $this->staff(StaffRole::SerologyTechnologist);
        $this->recordSerology($technologist)->assertCreated();

        $id = $this->ask($technologist, 'serology', $this->panel(['hbsag' => 'reactive', 'confirm_reactive' => true]))->json('data.id');

        // A bag is released before anyone decides on the correction.
        BloodUnit::factory()->create([
            'facility_id' => $this->facility->id,
            'donation_id' => $this->donation->id,
            'status' => BloodUnitStatus::Available,
        ]);

        $this->actingAs($this->staff(StaffRole::LabSupervisor))
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'units_released');

        $this->assertSame('pending', CorrectionRequest::findOrFail($id)->status);

        // Nothing was revoked either.
        $this->assertNull(DonationClearance::where('donation_id', $this->donation->id)->where('kind', 'tti')->sole()->revoked_at);
    }

    public function test_the_review_list_shows_an_approver_what_they_may_decide(): void
    {
        $phlebotomist = $this->staff(StaffRole::Phlebotomist);
        $physician = $this->staff(StaffRole::ScreeningPhysician);

        $id = $this->ask($phlebotomist, 'collection', $this->collectionBox())->json('data.id');

        $this->actingAs($physician)
            ->getJson('/api/blood-center/corrections?scope=review')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.can_decide', true)
            ->assertJsonPath('data.0.changed_fields', ['volume_ml', 'blood_bag_type', 'donation_barcode', 'started_at', 'ended_at']);

        $this->actingAs($this->staff(StaffRole::LabSupervisor))
            ->getJson('/api/blood-center/corrections?scope=review')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($phlebotomist)
            ->getJson('/api/blood-center/corrections?scope=mine')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.can_decide', false);
    }

    public function test_a_saved_record_is_not_saved_over_directly_even_by_a_supervisor(): void
    {
        $admin = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->actingAs($admin)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/components", [
                'components' => [['component_id' => BloodComponent::factory()->create()->id, 'volume_ml' => 250]],
            ])->assertCreated();

        $this->actingAs($admin)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/components", [
                'components' => [['component_id' => BloodComponent::factory()->create()->id, 'volume_ml' => 200]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'correction_required');
    }
}
