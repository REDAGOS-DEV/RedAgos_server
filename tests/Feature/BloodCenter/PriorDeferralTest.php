<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\ScreeningOutcome;
use App\Enums\StaffRole;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\DonorQrToken;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonorDeferred;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a permanent or indefinite deferral does, and what it deliberately does not.
 *
 * It tells the next counter and it changes what the donor is told. It blocks
 * nothing: a donor deferred at one centre can still book, answer the
 * questionnaire and be scanned at another. That is the decision, and the tests
 * that assert nothing is gated are as important as the ones that assert the
 * banner appears.
 */
class PriorDeferralTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->donor = User::factory()->donor()->create();
        $this->donor->donorProfile->update(['birth_date' => now()->subYears(30)->toDateString()]);
    }

    /**
     * Record a past screening for this donor, at any facility.
     */
    private function pastScreening(string $state, ?Facility $at = null): DonationScreening
    {
        $donation = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'donation_date' => now()->subMonths(6),
        ]);

        return DonationScreening::factory()->{$state}()->create([
            'donation_id' => $donation->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'recorded_by' => $this->staff->id,
            'screened_at' => now()->subMonths(6),
        ]);
    }

    /**
     * Put a live credential in the donor's hands.
     */
    private function scan(): TestResponse
    {
        $screening = EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);
        $raw = Str::random(40);

        DonorQrToken::factory()->create([
            'donor_id' => $this->donor->id,
            'screening_id' => $screening->id,
            'token_hash' => hash('sha256', $raw),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        return $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw]);
    }

    /**
     * Record a reactive serology result's referral for this donor, as LaboratoryService would.
     */
    private function laboratoryDeferral(?Facility $at = null, ?Carbon $on = null): CounsellingReferral
    {
        $at ??= $this->facility;

        $donation = Donation::factory()->rejectedAfterCollection()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $at->id,
            'donation_date' => $on ?? now()->subMonths(2),
        ]);

        $referral = CounsellingReferral::create([
            'donation_id' => $donation->id,
            'donor_id' => $this->donor->id,
            'facility_id' => $at->id,
        ]);

        $referral->forceFill(['created_at' => $on ?? now()->subMonths(2)])->save();

        return $referral;
    }

    // --- The laboratory's reactive result tells the counter too --------------

    public function test_a_reactive_serology_result_surfaces_as_a_permanent_deferral(): void
    {
        $this->laboratoryDeferral(on: now()->subMonths(2));

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral.outcome', 'permanently_deferred')
            ->assertJsonPath('data.prior_deferral.recorded_on', now()->subMonths(2)->toDateString());
    }

    public function test_a_reactive_result_at_another_centre_still_surfaces(): void
    {
        $this->laboratoryDeferral(Facility::factory()->approved()->create());

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral.outcome', 'permanently_deferred');
    }

    public function test_the_scan_cannot_tell_a_laboratory_deferral_from_a_screening_one(): void
    {
        $this->laboratoryDeferral();

        // The same three keys as a screening deferral. Which department
        // recorded it — and so that a laboratory finding exists — is not the
        // counter's to know.
        $this->assertSame(
            ['outcome', 'outcome_label', 'recorded_on'],
            array_keys($this->scan()->assertOk()->json('data.prior_deferral'))
        );
    }

    public function test_a_permanent_deferral_is_not_hidden_by_a_newer_indefinite_one(): void
    {
        $this->laboratoryDeferral(on: now()->subYear());
        $this->pastScreening('indefinitelyDeferred');

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral.outcome', 'permanently_deferred');
    }

    public function test_a_donor_found_by_id_number_carries_the_deferral_too(): void
    {
        $this->laboratoryDeferral();
        $this->donor->donorProfile->update([
            'valid_id_number' => AccountIdentity::normalizeValidIdNumber('PH-ID-000123'),
        ]);

        // A donor who left their phone at home must not walk past the warning.
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/donors/lookup?type=valid_id_number&value=PH-ID-000123')
            ->assertOk()
            ->assertJsonPath('prior_deferral.outcome', 'permanently_deferred');
    }

    public function test_the_staff_history_marks_the_laboratory_deferral_without_the_marker(): void
    {
        $this->laboratoryDeferral();

        $response = $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$this->donor->uuid}/history")
            ->assertOk();

        $this->assertSame('permanently_deferred', $response->json('donations.0.laboratory_deferral.outcome'));
        $this->assertTrue($response->json('donations.0.laboratory_deferral.is_blocking'));
        $this->assertStringNotContainsStringIgnoringCase('hiv', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('hbsag', $response->getContent());
    }

    // --- The scan tells the counter ------------------------------------------

    public function test_a_permanent_deferral_surfaces_on_the_scan(): void
    {
        $this->pastScreening('permanentlyDeferred');

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral.outcome', 'permanently_deferred')
            ->assertJsonPath('data.prior_deferral.outcome_label', 'Permanently Deferred')
            ->assertJsonPath('data.prior_deferral.recorded_on', now()->subMonths(6)->toDateString());
    }

    public function test_an_indefinite_deferral_surfaces_too(): void
    {
        $this->pastScreening('indefinitelyDeferred');

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral.outcome', 'indefinite_deferral');
    }

    public function test_the_scan_never_carries_the_reason(): void
    {
        $this->pastScreening('permanentlyDeferred');

        $body = $this->scan()->assertOk()->getContent();

        // What check-in needs is that a decision exists and when. The reason is
        // clinical detail and belongs behind the donor's history, which refuses
        // a facility the donor has no relationship with.
        $this->assertStringNotContainsString('Permanent deferral recorded', $body);
        $this->assertStringNotContainsString('deferral_reason', $body);
    }

    public function test_a_temporary_deferral_raises_no_banner(): void
    {
        $this->pastScreening('deferred');

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral', null);
    }

    public function test_a_donor_with_no_deferrals_carries_none(): void
    {
        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral', null);
    }

    public function test_a_deferral_recorded_at_another_centre_still_surfaces(): void
    {
        // A donor permanently deferred anywhere is permanently deferred, and
        // the counter that has never met them is the one that needs telling.
        $elsewhere = Facility::factory()->approved()->create();
        $this->pastScreening('permanentlyDeferred', $elsewhere);

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.prior_deferral.outcome', 'permanently_deferred');
    }

    // --- The reason lives in the gated history -------------------------------

    public function test_the_staff_history_carries_the_outcome_and_the_reason(): void
    {
        $this->pastScreening('permanentlyDeferred');

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$this->donor->uuid}/history")
            ->assertOk()
            ->assertJsonPath('donations.0.screening.outcome', 'permanently_deferred')
            ->assertJsonPath('donations.0.screening.is_blocking', true)
            ->assertJsonPath(
                'donations.0.screening.deferral_reason',
                'Permanent deferral recorded by the screening officer.'
            );
    }

    public function test_a_donation_with_no_screening_reports_none(): void
    {
        Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$this->donor->uuid}/history")
            ->assertOk()
            ->assertJsonPath('donations.0.screening', null);
    }

    // --- Nothing is gated ----------------------------------------------------

    public function test_a_permanently_deferred_donor_can_still_book(): void
    {
        // The banner is information for the officer, not a gate. If this test
        // ever fails, a block was introduced that nobody decided on.
        $this->pastScreening('permanentlyDeferred');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', [
                'type' => 'walkin',
                'center_id' => $this->facility->id,
                'date' => now()->addWeek()->toDateString(),
                'time_slot' => '09:00',
            ])
            ->assertCreated();
    }

    public function test_a_permanently_deferred_donor_can_still_be_scanned_in(): void
    {
        $this->pastScreening('permanentlyDeferred');

        $this->scan()->assertOk()->assertJsonPath('data.donor.uuid', $this->donor->uuid);
    }

    // --- What the donor is told ----------------------------------------------

    public function test_a_blocking_deferral_does_not_invite_the_donor_to_book_again(): void
    {
        $donation = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $notification = new DonorDeferred(
            $donation,
            'Recorded by the officer.',
            ScreeningOutcome::PermanentlyDeferred
        );

        $mail = $notification->toMail($this->donor)->render();
        $card = $notification->toDatabase($this->donor);

        // Telling someone who may never donate again to book another
        // appointment is the worst thing this feature can do.
        $this->assertStringNotContainsString('usually temporary', $mail);
        $this->assertStringNotContainsString('Book another appointment', $mail);
        $this->assertNull($card['action_label']);
        $this->assertNull($card['action_route']);
        $this->assertStringContainsString('speak to our staff', $card['meta']);
    }

    public function test_a_temporary_deferral_still_invites_the_donor_back(): void
    {
        $donation = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $notification = new DonorDeferred(
            $donation,
            'Haemoglobin below the accepted threshold.',
            ScreeningOutcome::TemporarilyDeferred
        );

        $this->assertStringContainsString('usually temporary', $notification->toMail($this->donor)->render());
        $this->assertSame('Book again', $notification->toDatabase($this->donor)['action_label']);
    }

    public function test_a_deferral_recorded_before_the_four_outcomes_keeps_its_original_wording(): void
    {
        // No outcome passed: every deferral on record before the form's four
        // REMARKS boxes existed was described to that donor as temporary, and
        // re-rendering it must not change what they were told.
        $donation = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $notification = new DonorDeferred($donation, 'A reason.');

        $this->assertStringContainsString('usually temporary', $notification->toMail($this->donor)->render());
    }
}
