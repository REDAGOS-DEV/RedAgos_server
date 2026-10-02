<?php

namespace Tests\Feature\Donor;

use App\Enums\DonationStatus;
use App\Enums\EligibilityStatus;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use App\Models\User;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Section I-B and I-C of the DOH questionnaire, from the donor's side.
 *
 * The governing rule throughout: RedAgos does not decide whether a donor may
 * give blood from their own answers. It records what was asked and answered,
 * and the blood centre decides at the counter. So a submission full of flagged
 * answers is still a 201 with a QR code, and no response anywhere carries a
 * verdict. What still refuses a submission are the three objective thresholds
 * -- age, weight and the donation interval -- which are arithmetic on records,
 * not readings of the questionnaire.
 */
class EligibilityScreeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EligibilityQuestionSeeder::class);
        $this->donor = User::factory()->donor()->create();
        $this->donor->donorProfile->update([
            'birth_date' => now()->subYears(30)->toDateString(),
            // Pinned so question 5 is predictably out of scope. A female donor
            // is covered by its own test below.
            'gender' => 'male',
        ]);
    }

    /**
     * Every question this donor is asked, answered "No" unless overridden.
     *
     * Built from the seeded bank rather than a hand-written list, so adding a
     * question to the form cannot leave this helper silently incomplete.
     *
     * @param  array<string, bool>  $overrides
     * @return array<int, array{code: string, answer: bool}>
     */
    private function answers(array $overrides = [], string $gender = 'male'): array
    {
        $codes = EligibilityQuestion::forVersion(2)
            ->get()
            ->filter(fn (EligibilityQuestion $q): bool => $q->appliesToGender($gender));

        return $codes
            ->map(fn (EligibilityQuestion $question): array => [
                'code' => $question->code,
                // The answer that trips no review marker: the opposite of the
                // flag where one is set, otherwise No. Derived rather than
                // listed, so a new question cannot silently flag this baseline
                // -- question 29 is flagged on FALSE, which a blanket "No"
                // would trip.
                'answer' => $overrides[$question->code]
                    ?? ($question->disqualify_if_answer === null
                        ? false
                        : ! $question->disqualify_if_answer),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'question_version' => 2,
            'answers' => $this->answers(),
            'consent' => [
                'version' => config('donor_consent.current'),
                'accepted' => true,
            ],
            'vitals' => ['weight' => 65],
        ], $overrides);
    }

    // --- Serving the questionnaire ---------------------------------------

    public function test_the_questionnaire_is_served_without_its_review_markers(): void
    {
        $response = $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk()
            ->assertJsonPath('version', 2)
            // Five, not six: the female-donor section is omitted entirely.
            ->assertJsonCount(5, 'sections');

        $body = $response->getContent();

        // Which answers draw a nurse's attention is the blood centre's
        // business. Telling the donor would let them work backwards to the
        // answers that avoid a second look.
        $this->assertStringNotContainsString('disqualify', $body);
        $this->assertStringContainsString('v2_ay_1', $body);
    }

    public function test_the_sections_carry_the_headings_printed_on_the_form(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk()
            ->assertJsonPath('sections.0.title', 'Are you')
            ->assertJsonPath('sections.0.number', 1)
            ->assertJsonPath('sections.1.title', 'In the past three days')
            // Indices shift for a male donor, so pin by the section's own
            // number rather than its position.
            ->assertJsonPath('sections.3.number', 5)
            ->assertJsonPath('sections.3.title', 'In the past 12 months, have you');
    }

    public function test_the_consent_statements_are_served_with_the_questions(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk()
            ->assertJsonPath('consent.version', config('donor_consent.current'))
            ->assertJsonCount(5, 'consent.statements');
    }

    public function test_question_five_is_withheld_from_a_male_donor(): void
    {
        $body = $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk()
            ->getContent();

        // Omitted rather than served-and-skipped: answering "not pregnant" for
        // a male donor would write a clinical falsehood into the record.
        $this->assertStringNotContainsString('v2_fd_1', $body);
    }

    public function test_a_male_donor_is_not_required_to_answer_question_five(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    public function test_question_five_is_required_of_a_female_donor(): void
    {
        $this->donor->donorProfile->update(['gender' => 'female']);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk()
            ->assertJsonPath('sections.2.questions.0.code', 'v2_fd_1')
            ->assertJsonPath('sections.2.questions.0.required', true);

        // Omitting it must not slip through as a complete submission.
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(gender: 'male'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_donor_who_withheld_their_gender_is_offered_question_five_but_not_required_to_answer(): void
    {
        $this->donor->donorProfile->update(['gender' => 'prefer_not_to_say']);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk()
            // Offered, because dropping a physiological safety question over a
            // privacy choice is the wrong way to be discreet.
            ->assertJsonPath('sections.2.questions.0.code', 'v2_fd_1')
            ->assertJsonPath('sections.2.questions.0.required', false);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(gender: 'male'),
            ]))
            ->assertCreated();
    }

    public function test_the_acknowledgement_question_is_marked_as_one(): void
    {
        $response = $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk();

        $question = collect($response->json('sections'))
            ->flatMap(fn (array $section): array => $section['questions'])
            ->firstWhere('code', 'v2_ev_13');

        $this->assertSame('acknowledgement', $question['kind']);
        $this->assertSame(29, $question['number']);
    }

    // --- Submitting, and the absence of a verdict -------------------------

    public function test_a_submission_records_the_questionnaire_without_ruling_on_it(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('screening_valid_until', now()->addDays(90)->toDateString());

        $body = $response->getContent();

        $this->assertStringNotContainsString('eligible', $body);
        $this->assertStringNotContainsString('deferred', $body);
        $this->assertArrayNotHasKey('result', $response->json());
        $this->assertArrayNotHasKey('deferral_reasons', $response->json());

        $this->assertSame(
            EligibilityStatus::Pending,
            EligibilityScreening::latest('id')->firstOrFail()->result
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function flaggedAnswers(): array
    {
        return [
            'not feeling well' => ['v2_ay_1', false],
            'donated within three months' => ['v2_m3_1', true],
            'recent transfusion' => ['v2_m12_1', true],
            'positive HIV or syphilis test' => ['v2_ev_5', true],
            'previous hepatitis' => ['v2_ev_6', true],
            'donating in order to be tested' => ['v2_ev_12', true],
            'not aware of the transmission risk' => ['v2_ev_13', false],
        ];
    }

    /**
     * The heart of the change: a flagged answer marks a row for the counter
     * and turns nobody away.
     */
    #[DataProvider('flaggedAnswers')]
    public function test_a_flagged_answer_neither_defers_the_donor_nor_withholds_their_qr(
        string $code,
        bool $answer
    ): void {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers([$code => $answer]),
            ]))
            ->assertCreated();

        $this->assertNotEmpty($response->json('qr_token'));

        $screening = EligibilityScreening::latest('id')->firstOrFail();

        $this->assertSame(EligibilityStatus::Pending, $screening->result);
        // Recorded for the blood centre, never shown to the donor.
        $this->assertSame('deferred', $screening->computed_result);
    }

    public function test_every_answer_flagged_at_once_still_issues_a_qr(): void
    {
        $flipped = EligibilityQuestion::forVersion(2)
            ->whereNotNull('disqualify_if_answer')
            ->get()
            ->mapWithKeys(fn (EligibilityQuestion $q): array => [
                $q->code => (bool) $q->disqualify_if_answer,
            ])
            ->all();

        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers($flipped),
            ]))
            ->assertCreated();

        $this->assertNotEmpty($response->json('qr_token'));
    }

    public function test_an_unflagged_submission_is_assessed_as_clear_for_the_counter(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->assertSame(
            'eligible',
            EligibilityScreening::latest('id')->firstOrFail()->computed_result
        );
    }

    public function test_the_audit_entry_names_the_versions_and_counts_but_no_answers(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['v2_ev_5' => true]),
            ]))
            ->assertCreated();

        $log = AuditLog::where('action', 'eligibility.screening.created')->firstOrFail();

        $this->assertSame($this->donor->id, $log->actor_id);
        $this->assertSame(2, $log->context['question_version']);
        $this->assertSame(1, $log->context['flagged_count']);
        $this->assertStringNotContainsString('v2_ev_5', json_encode($log->context));
    }

    // --- Consent ----------------------------------------------------------

    public function test_a_submission_without_consent_is_refused(): void
    {
        $payload = $this->payload();
        unset($payload['consent']);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('consent');
    }

    public function test_consent_that_is_not_accepted_is_refused(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'consent' => ['version' => config('donor_consent.current'), 'accepted' => false],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('consent.accepted');
    }

    public function test_consent_given_against_withdrawn_wording_is_refused(): void
    {
        config(['donor_consent.current' => 'doh-9999-99']);
        config(['donor_consent.versions.doh-9999-99' => ['statements' => ['New wording.']]]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'consent' => ['version' => 'doh-2023-07', 'accepted' => true],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'consent_version_stale');
    }

    public function test_consent_is_recorded_with_a_digest_of_what_was_shown(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('consented_on', now()->toDateString());

        $screening = EligibilityScreening::latest('id')->firstOrFail();
        $version = config('donor_consent.current');

        $this->assertNotNull($screening->consented_at);
        $this->assertSame($version, $screening->consent_version);
        $this->assertSame(
            hash('sha256', implode("\n", config('donor_consent.versions.'.$version.'.statements'))),
            $screening->consent_text_hash
        );
    }

    public function test_the_gender_asked_against_is_frozen_onto_the_screening(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        // Editing the profile afterwards must not rewrite what this record says
        // was asked.
        $this->donor->donorProfile->update(['gender' => 'female']);

        $this->assertSame(
            'male',
            EligibilityScreening::latest('id')->firstOrFail()->gender_at_screening
        );
    }

    // --- The objective thresholds still refuse -----------------------------

    public function test_a_weight_below_fifty_kilograms_refuses_the_submission(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 49],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'threshold_not_met')
            ->assertJsonPath('reasons.0.code', 'below_min_weight')
            ->assertJsonPath('message', 'Donors must weigh at least 50 kilograms.');

        $this->assertSame(0, EligibilityScreening::count());
    }

    public function test_a_weight_of_exactly_fifty_kilograms_is_accepted(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 50],
            ]))
            ->assertCreated();
    }

    public function test_a_donation_fifty_five_days_ago_refuses_on_the_interval(): void
    {
        Donation::factory()->completedAt(now()->subDays(55)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('reasons.0.code', 'below_min_interval');
    }

    public function test_a_donation_fifty_seven_days_ago_is_accepted(): void
    {
        Donation::factory()->completedAt(now()->subDays(57)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    public function test_the_interval_ignores_a_donor_turned_away_before_anything_was_drawn(): void
    {
        Donation::factory()->rejected()->create([
            'donor_id' => $this->donor->id,
            'donation_date' => now()->subDay(),
        ]);

        // Nothing came out of their arm, so nothing is protecting them from
        // donating today.
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    public function test_the_interval_counts_a_donation_drawn_and_then_rejected(): void
    {
        Donation::factory()->rejectedAfterCollection(now()->subDay()->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        // The bag was reactive and never reached a patient, but 450 mL still
        // left this donor yesterday.
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('reasons.0.code', 'below_min_interval');
    }

    public function test_the_interval_counts_a_donation_still_waiting_on_the_laboratory(): void
    {
        Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'donation_date' => now()->subDay(),
            'status' => DonationStatus::Collected,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('reasons.0.code', 'below_min_interval');
    }

    public function test_a_self_declared_last_donation_date_cannot_bypass_the_interval(): void
    {
        Donation::factory()->completedAt(now()->subDays(10)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 65, 'last_donation_date' => now()->subYears(5)->toDateString()],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('reasons.0.code', 'below_min_interval');
    }

    public function test_a_donor_under_eighteen_is_refused_on_their_stored_birth_date(): void
    {
        $this->donor->donorProfile->update(['birth_date' => now()->subYears(16)->toDateString()]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('reasons.0.code', 'below_min_age');
    }

    public function test_several_breached_thresholds_are_all_reported(): void
    {
        $this->donor->donorProfile->update(['birth_date' => now()->subYears(15)->toDateString()]);

        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 40],
            ]))
            ->assertStatus(422);

        $this->assertEqualsCanonicalizing(
            ['below_min_age', 'below_min_weight'],
            array_column($response->json('reasons'), 'code')
        );
    }

    // --- Completeness, versioning and re-screening -------------------------

    public function test_an_incomplete_answer_set_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => [['code' => 'v2_ay_1', 'answer' => true]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_stale_questionnaire_version_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'question_version' => 99,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'questionnaire_version_stale')
            ->assertJsonPath('current_version', 2);
    }

    public function test_a_version_with_no_seeded_questions_is_refused_rather_than_served_empty(): void
    {
        // The dangerous state: the version is bumped before the rows are
        // seeded, zero questions are served, the completeness check passes on
        // an empty set, and every submission is recorded as a fully answered
        // questionnaire nobody was ever asked.
        config(['donation.questionnaire_version' => 98]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertStatus(503)
            ->assertJsonPath('code', 'questionnaire_unavailable');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'question_version' => 98,
                'answers' => [],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, EligibilityScreening::count());
    }

    public function test_re_screening_while_a_valid_questionnaire_stands_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'screening_already_valid');
    }

    public function test_re_screening_can_be_forced(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening?force=1', $this->payload())
            ->assertCreated();

        $this->assertSame(2, EligibilityScreening::count());
    }

    public function test_a_holder_of_a_superseded_version_may_answer_again_without_forcing(): void
    {
        // Answering a different form is not a pointless duplicate, so the
        // guard that stops those must not stand in the way of it.
        EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'question_version' => 1,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    // --- Storage and exposure ---------------------------------------------

    public function test_answers_are_encrypted_at_rest(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['v2_ev_5' => true]),
            ]))
            ->assertCreated();

        $raw = DB::table('eligibility_screening_answers')
            ->where('question_code', 'v2_ev_5')
            ->value('answer');

        $this->assertNotSame('1', $raw);
        $this->assertNotSame('true', $raw);
        $this->assertGreaterThan(20, strlen($raw));

        $this->assertTrue(
            EligibilityScreeningAnswer::where('question_code', 'v2_ev_5')->firstOrFail()->answer
        );
    }

    public function test_answers_never_appear_in_a_screening_response(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['v2_ev_5' => true]),
            ]))
            ->assertCreated();

        $this->assertStringNotContainsString('v2_ev_5', $response->getContent());
        $this->assertArrayNotHasKey('answers', $response->json());
    }

    // --- Prefill and status ------------------------------------------------

    public function test_prefill_returns_server_derived_values_only(): void
    {
        Donation::factory()->completedAt('2026-01-15')->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/prefill')
            ->assertOk()
            ->assertJsonPath('age', 30)
            ->assertJsonPath('last_donation_date', '2026-01-15');
    }

    public function test_the_status_endpoint_reports_not_answered_before_any_questionnaire(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('questionnaire_status', 'not_answered');
    }

    public function test_the_status_endpoint_reports_answered_once_submitted(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('questionnaire_status', 'answered')
            ->assertJsonPath('screening_question_version', 2)
            ->assertJsonPath('re_screen_recommended', false);
    }

    public function test_the_status_endpoint_reports_expired_once_validity_lapses(): void
    {
        EligibilityScreening::factory()->expired()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('questionnaire_status', 'expired');
    }

    public function test_a_superseded_version_is_recommended_for_re_answering(): void
    {
        EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'question_version' => 1,
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('questionnaire_status', 'answered')
            ->assertJsonPath('re_screen_recommended', true);
    }
}
