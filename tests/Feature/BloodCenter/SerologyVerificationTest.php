<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Mail\DonorCenterEmail;
use App\Models\AuditLog;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationSerology;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonorContactRequested;
use App\Notifications\SerologyVerificationRequested;
use App\Service\DonorStandingResolver;
use App\Support\DonorStanding;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * A reactive result waits for a second person (project owner, 2026-10-18).
 *
 * What is locked in: a technologist's reactive panel sets nothing off — no
 * rejection, no deferral, no referral, nothing to the donor — until the
 * Laboratory Supervisor verifies it, or returns it for re-entry; the
 * supervisor's own reactive panel and an approved correction take effect at
 * once; and the donor is asked to get in touch only by hand, from the
 * referral list, in words that name no result.
 */
class SerologyVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $technologist;

    private User $supervisor;

    private User $donor;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->facility = Facility::factory()->approved()->create(['phone' => '(02) 8123 4567']);
        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->supervisor = User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create();
        $this->donor = User::factory()->donor()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $this->donor->id,
            'status' => DonationStatus::Collected,
        ]);
    }

    private function reactive(?User $as = null, string $marker = 'hiv'): TestResponse
    {
        return $this->actingAs($as ?? $this->technologist)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/serology",
            [
                'hiv' => 'non_reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive', 'malaria' => 'non_reactive',
                $marker => 'reactive',
                'confirm_reactive' => true,
            ]
        );
    }

    private function act(string $action, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->supervisor)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/serology/{$action}");
    }

    private function standing(): string
    {
        return app(DonorStandingResolver::class)->forDonor($this->facility->id, $this->donor)->eligibility();
    }

    // --- Held until verified -------------------------------------------------

    public function test_a_technologists_reactive_panel_sets_nothing_off(): void
    {
        $this->reactive()
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.serology.outcome', 'reactive')
            ->assertJsonPath('data.serology.awaiting_verification', true)
            ->assertJsonPath('data.clearances.tti', false);

        $this->assertSame(DonationStatus::Collected, $this->donation->fresh()->status);
        $this->assertDatabaseCount('counselling_referrals', 0);
        $this->assertNotSame(DonorStanding::DEFERRED, $this->standing());
        Notification::assertNotSentTo($this->donor, DonorContactRequested::class);
    }

    public function test_the_supervisor_is_told_without_the_donor_or_the_marker(): void
    {
        $this->reactive(marker: 'hcv')->assertCreated();

        Notification::assertSentTo($this->supervisor, SerologyVerificationRequested::class, function (SerologyVerificationRequested $notice): bool {
            $this->assertSame('/blood-center/serology-results?outcome=awaiting_verification', $notice->toDatabase($this->supervisor)['action_route']);

            return true;
        });

        Notification::assertSentTo($this->supervisor, SerologyVerificationRequested::class, function (SerologyVerificationRequested $notice): bool {
            $text = strtolower(json_encode($notice->toDatabase($this->supervisor)));

            foreach (['hcv', 'hepatitis', 'ana', 'dela cruz'] as $word) {
                $this->assertStringNotContainsString($word, $text, "The notice must not say '{$word}'.");
            }

            return true;
        });
        Notification::assertNotSentTo($this->technologist, SerologyVerificationRequested::class);
    }

    public function test_outside_testing_a_waiting_result_reads_as_a_wait(): void
    {
        $this->reactive()->assertCreated();

        $this->actingAs(User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create())
            ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
            ->assertOk()
            ->assertJsonPath('serology.outcome', 'awaiting_verification')
            ->assertJsonPath('serology.markers', null);
    }

    public function test_verifying_it_rejects_defers_and_refers_but_contacts_nobody(): void
    {
        $this->reactive()->assertCreated();

        $this->act('verify')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.serology.awaiting_verification', false)
            ->assertJsonPath('data.serology.verified_by', trim($this->supervisor->first_name.' '.$this->supervisor->last_name));

        $this->assertDatabaseCount('counselling_referrals', 1);
        $this->assertSame(DonorStanding::DEFERRED, $this->standing());
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.serology_verified', 'actor_id' => $this->supervisor->id]);
        Notification::assertNotSentTo($this->donor, DonorContactRequested::class);

        // Verified, it can no longer be returned, re-entered or corrected.
        $this->act('return')->assertStatus(409)->assertJsonPath('code', 'nothing_to_verify');
    }

    public function test_returning_it_clears_the_panel_for_re_entry(): void
    {
        $this->reactive(marker: 'malaria')->assertCreated();

        $this->act('return')->assertOk()->assertJsonPath('data.serology', null);

        $this->assertDatabaseCount('donation_serology', 0);
        $this->assertDatabaseCount('counselling_referrals', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.serology_returned', 'actor_id' => $this->supervisor->id]);
        AuditLog::all()->each(fn (AuditLog $log) => $this->assertStringNotContainsStringIgnoringCase('malaria', json_encode($log->context)));

        // Re-entered correctly, it clears as any non-reactive panel does.
        $this->actingAs($this->technologist)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/serology",
            ['hiv' => 'non_reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive', 'syphilis' => 'non_reactive', 'malaria' => 'non_reactive']
        )->assertCreated()->assertJsonPath('data.clearances.tti', true);
    }

    public function test_only_the_supervisor_verifies_or_returns(): void
    {
        $this->reactive()->assertCreated();

        $this->act('verify', $this->technologist)->assertForbidden();
        $this->act('return', $this->technologist)->assertForbidden();
    }

    public function test_the_supervisors_own_reactive_panel_takes_effect_at_once(): void
    {
        $this->reactive($this->supervisor)
            ->assertCreated()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.serology.awaiting_verification', false);

        Notification::assertNothingSentTo($this->supervisor);
    }

    public function test_a_waiting_result_is_verified_or_returned_never_corrected(): void
    {
        $this->reactive()->assertCreated();

        $this->actingAs($this->technologist)->postJson("/api/blood-center/donations/{$this->donation->id}/corrections", [
            'subject' => 'serology',
            'reason' => 'Wrong row.',
            'changes' => ['hiv' => 'non_reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive', 'syphilis' => 'non_reactive', 'malaria' => 'non_reactive'],
        ])->assertStatus(409)->assertJsonPath('code', 'verification_pending');
    }

    public function test_an_approved_correction_to_reactive_is_verified_by_its_approver(): void
    {
        $this->actingAs($this->technologist)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/serology",
            ['hiv' => 'non_reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive', 'syphilis' => 'non_reactive', 'malaria' => 'non_reactive']
        )->assertCreated();

        $id = $this->actingAs($this->technologist)->postJson("/api/blood-center/donations/{$this->donation->id}/corrections", [
            'subject' => 'serology',
            'reason' => 'HIV well read against the wrong row.',
            'changes' => ['hiv' => 'reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive', 'syphilis' => 'non_reactive', 'malaria' => 'non_reactive', 'confirm_reactive' => true],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $serology = DonationSerology::where('donation_id', $this->donation->id)->sole();
        $this->assertSame($this->supervisor->id, (int) $serology->verified_by);
        $this->assertSame(DonationStatus::Rejected, $this->donation->fresh()->status);
    }

    public function test_only_a_verified_reactive_panel_is_immutable(): void
    {
        $this->reactive()->assertCreated();
        $serology = DonationSerology::where('donation_id', $this->donation->id)->sole();

        $this->assertTrue($serology->isAwaitingVerification());

        $this->act('verify')->assertOk();

        $this->expectException(LogicException::class);
        $serology->fresh()->delete();
    }

    // --- The contact request, by hand --------------------------------------------

    private function referral(): CounsellingReferral
    {
        $this->reactive($this->supervisor, 'hcv')->assertCreated();

        return CounsellingReferral::where('donation_id', $this->donation->id)->sole();
    }

    public function test_the_contact_request_goes_by_email_and_in_the_app_naming_no_result(): void
    {
        $referral = $this->referral();

        $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/laboratory/referrals/{$referral->id}/contact-request")
            ->assertOk()
            ->assertJsonPath('data.contact_requested_by', trim($this->supervisor->first_name.' '.$this->supervisor->last_name));

        Mail::assertQueued(DonorCenterEmail::class, function (DonorCenterEmail $mail): bool {
            $this->assertTrue($mail->hasTo('ana@example.test'));
            $this->assertSame($this->facility->name, $mail->centerName);

            $text = strtolower($mail->subjectLine.' '.implode(' ', $mail->paragraphs));
            $this->assertStringContainsString('(02) 8123 4567', $text);

            foreach (['hcv', 'hepatitis', 'reactive', 'result', 'deferr', 'infection', 'test'] as $word) {
                $this->assertStringNotContainsString($word, $text, "The email must not say '{$word}'.");
            }

            return true;
        });

        Notification::assertSentTo($this->donor, DonorContactRequested::class);
        $this->assertNotNull($referral->fresh()->contact_requested_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referral.contact_requested', 'actor_id' => $this->supervisor->id]);
    }

    public function test_a_donor_without_email_is_asked_in_the_app_only(): void
    {
        $this->donor->forceFill(['email' => null])->save();
        $referral = $this->referral();

        $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/laboratory/referrals/{$referral->id}/contact-request")
            ->assertOk()
            // Never the donor's name: Testing works blind, its head included.
            ->assertJsonPath('message', 'The donor has no email address, so the contact request was sent in the portal only.');

        Mail::assertNothingQueued();
        Notification::assertSentTo($this->donor, DonorContactRequested::class);
    }

    public function test_the_sidebar_counts_waiting_referrals_without_auditing_a_read(): void
    {
        $this->referral();

        $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/laboratory/referrals/summary')
            ->assertOk()
            ->assertExactJson(['pending_count' => 1]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'referral.list_viewed']);
        $this->actingAs($this->technologist)->getJson('/api/blood-center/laboratory/referrals/summary')->assertForbidden();
    }

    public function test_a_closed_referral_sends_nothing_and_technologists_cannot_send(): void
    {
        $referral = $this->referral();

        $this->actingAs($this->technologist)
            ->postJson("/api/blood-center/laboratory/referrals/{$referral->id}/contact-request")
            ->assertForbidden();

        $referral->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/laboratory/referrals/{$referral->id}/contact-request")
            ->assertStatus(409)
            ->assertJsonPath('code', 'referral_closed');

        Mail::assertNothingQueued();
    }
}
