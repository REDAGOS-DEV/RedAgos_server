<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\ReferralStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodType;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonorContactRequested;
use App\Notifications\DonorDeferred;
use App\Service\LaboratoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RecordsTyping;
use Tests\TestCase;

/**
 * The Testing department's two sections of Section II of the DOH form.
 *
 * Immunohematology and the five-marker serology panel are saved separately,
 * each stamped with who saved it. Both non-reactive hands the donation to
 * Processing; any reactive marker rejects it, permanently defers the donor,
 * refers them for counselling and asks them — without saying why — to get in
 * touch. Which marker it was is shown to Testing and nobody else.
 */
class TestingSectionsTest extends TestCase
{
    use LazilyRefreshDatabase, RecordsTyping;

    private Facility $facility;

    private User $testing;

    private User $typist;

    private User $processing;

    private User $donor;

    private BloodType $bloodType;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->testing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->typist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->processing = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();

        $this->donor = User::factory()->donor()->create();
        $this->bloodType = $this->donor->donorProfile->bloodType;

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $this->donor->id,
            'status' => DonationStatus::Collected,
        ]);
    }

    private function typing(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->typist)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/immunohematology",
            $this->concordantTyping($this->bloodType)
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function serology(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->testing)->postJson(
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

    private function reactive(string $marker = 'hbsag'): TestResponse
    {
        return $this->serology([$marker => 'reactive', 'confirm_reactive' => true]);
    }

    // --- Two sections, either order ----------------------------------------

    public function test_one_section_alone_leaves_the_donation_with_testing(): void
    {
        $this->typing()
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.owning_department', 'testing')
            ->assertJsonPath('data.test_result', null)
            ->assertJsonPath('data.immunohematology.blood_type', $this->bloodType->code);
    }

    public function test_serology_may_be_recorded_before_typing(): void
    {
        $this->serology()
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.serology.outcome', 'non_reactive');

        $this->typing()
            ->assertCreated()
            ->assertJsonPath('data.status', 'tested')
            ->assertJsonPath('data.owning_department', 'processing')
            ->assertJsonPath('data.test_result.result', 'passed')
            ->assertJsonPath('data.test_result.blood_type', $this->bloodType->code);
    }

    public function test_each_section_is_stamped_with_whoever_saved_it(): void
    {
        $colleague = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();

        $this->typing()->assertCreated();
        $this->serology([], $colleague)->assertCreated();

        $this->assertDatabaseHas('donation_immunohematology', [
            'donation_id' => $this->donation->id,
            'recorded_by' => $this->typist->id,
        ]);
        $this->assertDatabaseHas('donation_serology', [
            'donation_id' => $this->donation->id,
            'recorded_by' => $colleague->id,
        ]);
    }

    public function test_screened_by_is_never_taken_from_the_request(): void
    {
        $someoneElse = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();

        $this->serology(['recorded_by' => $someoneElse->id])->assertCreated();

        $this->assertDatabaseHas('donation_serology', [
            'donation_id' => $this->donation->id,
            'recorded_by' => $this->testing->id,
        ]);
    }

    public function test_the_response_names_who_screened_each_section(): void
    {
        $this->typing()->assertCreated();

        $this->serology()
            ->assertCreated()
            ->assertJsonPath('data.immunohematology.recorded_by', trim($this->typist->first_name.' '.$this->typist->last_name))
            ->assertJsonPath('data.serology.recorded_by', trim($this->testing->first_name.' '.$this->testing->last_name));
    }

    // --- The panel is exactly five final readings --------------------------

    public function test_every_marker_is_required(): void
    {
        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/serology", ['hiv' => 'non_reactive'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hbsag', 'hcv', 'syphilis', 'malaria']);

        $this->assertDatabaseCount('donation_serology', 0);
    }

    public function test_a_marker_is_reactive_or_non_reactive_and_nothing_else(): void
    {
        $this->serology(['malaria' => 'inconclusive'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('malaria');
    }

    public function test_extra_markers_are_ignored(): void
    {
        // NAT and "Others" on the printed form are deliberately not recorded.
        $this->serology(['nat' => 'reactive'])
            ->assertCreated()
            ->assertJsonPath('data.serology.outcome', 'non_reactive');
    }

    // --- A reactive marker --------------------------------------------------

    public function test_a_reactive_marker_must_be_confirmed(): void
    {
        $this->serology(['hiv' => 'reactive'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_reactive');

        $this->serology(['hiv' => 'reactive', 'confirm_reactive' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_reactive');

        // Nothing happened: no reading, no rejection, no referral.
        $this->assertDatabaseCount('donation_serology', 0);
        $this->assertDatabaseCount('counselling_referrals', 0);
        $this->assertSame(DonationStatus::Collected, $this->donation->fresh()->status);
    }

    public function test_a_reactive_marker_rejects_the_donation_with_a_reason_that_names_no_marker(): void
    {
        $this->reactive('hiv')
            ->assertCreated()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', LaboratoryService::REACTIVE_REJECTION_REASON);

        $this->assertStringNotContainsStringIgnoringCase('hiv', (string) $this->donation->fresh()->rejection_reason);
    }

    public function test_typing_is_not_needed_before_a_reactive_result_rejects(): void
    {
        $this->reactive()->assertCreated();

        $this->assertSame(DonationStatus::Rejected, $this->donation->fresh()->status);
    }

    public function test_a_reactive_marker_opens_a_pending_counselling_referral(): void
    {
        $this->reactive('syphilis')->assertCreated();

        $referral = CounsellingReferral::where('donation_id', $this->donation->id)->firstOrFail();

        $this->assertSame(ReferralStatus::Pending, $referral->status);
        $this->assertSame($this->donor->id, (int) $referral->donor_id);
        $this->assertSame($this->facility->id, (int) $referral->facility_id);
    }

    public function test_the_donor_is_asked_to_get_in_touch_without_being_told_why(): void
    {
        $this->reactive('hcv')->assertCreated();

        Notification::assertSentTo($this->donor, DonorContactRequested::class, function (DonorContactRequested $notification): bool {
            $this->assertSame(['database'], $notification->via($this->donor));

            $text = strtolower(json_encode($notification->toDatabase($this->donor)));

            foreach (['hcv', 'hepatitis', 'reactive', 'result', 'deferred', 'infection'] as $word) {
                $this->assertStringNotContainsString($word, $text, "The notice must not say '{$word}'.");
            }

            return true;
        });

        // DonorDeferred prints the recorded reason, so it is never used here.
        Notification::assertNotSentTo($this->donor, DonorDeferred::class);
    }

    public function test_the_audit_trail_records_the_outcome_but_never_the_marker(): void
    {
        $this->reactive('malaria')->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.serology_recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.donation_rejected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referral.opened']);

        AuditLog::all()->each(function (AuditLog $log): void {
            $this->assertStringNotContainsStringIgnoringCase('malaria', json_encode($log->context));
        });
    }

    public function test_testing_rejects_automatically_without_holding_the_status_ability(): void
    {
        $this->assertFalse($this->testing->can('lab.update_status'));

        $this->reactive()->assertCreated();

        $this->assertSame(DonationStatus::Rejected, $this->donation->fresh()->status);
    }

    public function test_a_reactive_result_on_a_typed_donation_writes_a_reactive_summary(): void
    {
        $this->typing()->assertCreated();
        $this->reactive()->assertCreated()->assertJsonPath('data.test_result.result', 'reactive');
    }

    public function test_correcting_a_non_reactive_panel_to_reactive_still_rejects(): void
    {
        $this->serology()->assertCreated()->assertJsonPath('data.status', 'collected');

        // The saved panel is already TTI-cleared, but its bags have not left
        // quarantine, so it is corrected — through a request the Laboratory
        // Supervisor approves.
        $correction = $this->actingAs($this->testing)
            ->postJson("/api/blood-center/donations/{$this->donation->id}/corrections", [
                'subject' => 'serology',
                'reason' => 'HIV well read against the wrong row.',
                'changes' => [
                    'hiv' => 'reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive',
                    'syphilis' => 'non_reactive', 'malaria' => 'non_reactive',
                    'confirm_reactive' => true,
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs(User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create())
            ->postJson("/api/blood-center/corrections/{$correction}/approve")
            ->assertOk();

        $this->assertSame(DonationStatus::Rejected, $this->donation->fresh()->status);
        $this->assertDatabaseCount('counselling_referrals', 1);
    }

    public function test_a_reactive_result_rejects_a_donation_processing_already_completed(): void
    {
        // Processing does not wait for the laboratory, so a reactive result can
        // arrive after the bags were handed to Issuance. It still rejects.
        $this->donation->update(['status' => DonationStatus::Completed]);

        $this->reactive()->assertCreated()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseCount('counselling_referrals', 1);
    }

    public function test_results_are_locked_once_the_donation_is_rejected(): void
    {
        $this->reactive()->assertCreated();

        // A reactive result cannot be quietly undone: it has already deferred
        // and referred the donor.
        $this->serology()
            ->assertStatus(409)
            ->assertJsonPath('code', 'results_locked');

        $this->typing()
            ->assertStatus(409)
            ->assertJsonPath('code', 'results_locked');
    }

    public function test_a_completed_donation_can_still_be_tested(): void
    {
        $this->donation->update(['status' => DonationStatus::Completed]);

        $this->typing()->assertCreated();
        $this->serology()->assertCreated()->assertJsonPath('data.status', 'completed');
    }

    // --- Who sees which marker ----------------------------------------------

    public function test_testing_sees_each_marker(): void
    {
        $this->serology(['hbsag' => 'reactive', 'confirm_reactive' => true])->assertCreated();

        $markers = collect(
            $this->actingAs($this->testing)
                ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
                ->assertOk()
                ->json('serology.markers')
        )->pluck('result', 'marker');

        $this->assertSame('reactive', $markers['hbsag']);
        $this->assertSame('non_reactive', $markers['hiv']);
    }

    public function test_processing_sees_the_outcome_but_never_the_marker(): void
    {
        $this->reactive('hbsag')->assertCreated();

        $response = $this->actingAs($this->processing)
            ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
            ->assertOk()
            ->assertJsonPath('serology.outcome', 'reactive')
            ->assertJsonPath('serology.markers', null);

        $this->assertStringNotContainsStringIgnoringCase('hbsag', $response->getContent());
    }

    // --- The Testing queue ---------------------------------------------------

    public function test_the_testing_queue_holds_donations_waiting_on_a_section(): void
    {
        $queue = fn (): array => collect(
            $this->actingAs($this->testing)
                ->getJson('/api/blood-center/laboratory/queue?stage=testing')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($this->donation->id, $queue());

        $this->typing()->assertCreated();
        $this->assertContains($this->donation->id, $queue());

        $this->serology()->assertCreated();
        $this->assertNotContains($this->donation->id, $queue());
    }

    public function test_a_scanned_segment_finds_its_donation(): void
    {
        $this->donation->collection()->update([
            'facility_id' => $this->facility->id,
            'segment_number' => 'SEG-7781',
        ]);

        $other = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $this->donor->id,
            'status' => DonationStatus::Collected,
        ]);

        $ids = collect(
            $this->actingAs($this->testing)
                ->getJson('/api/blood-center/laboratory/queue?stage=testing&segment_number='.urlencode(' seg-7781 '))
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertContains($this->donation->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }
}
