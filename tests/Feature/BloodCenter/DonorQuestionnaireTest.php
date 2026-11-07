<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\EligibilityStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use App\Models\Facility;
use App\Models\User;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sections I-A to I-C of the DOH questionnaire, as the collection counter reads
 * them after a scan.
 *
 * Two rules run through everything here. The scan response carries a reference
 * and never the answers, because it is about who is at the counter. And the
 * document itself is reachable only by staff who hold the questionnaire ability
 * and only for a donor who has actually presented at their facility.
 */
class DonorQuestionnaireTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(EligibilityQuestionSeeder::class);

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();

        $this->donor = User::factory()->donor()->create(['middle_name' => 'Reyes']);
        $this->donor->donorProfile->update([
            'birth_date' => now()->subYears(30)->toDateString(),
            'gender' => 'male',
            'civil_status' => 'single',
            'occupation' => 'Teacher',
            'nationality' => 'Filipino',
            'address' => '12 Mabini St, Davao City',
        ]);
    }

    /**
     * Record a completed questionnaire the way EligibilityService does.
     */
    private function answerQuestionnaire(array $overrides = [], array $screeningOverrides = []): EligibilityScreening
    {
        $screening = EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'question_version' => 2,
            'result' => EligibilityStatus::Pending,
            'computed_result' => 'eligible',
            'gender_at_screening' => 'male',
            'consented_at' => now(),
            'consent_version' => config('donor_consent.current'),
            ...$screeningOverrides,
        ]);

        EligibilityQuestion::forVersion(2)
            ->get()
            ->filter(fn (EligibilityQuestion $q): bool => $q->appliesToGender('male'))
            ->each(function (EligibilityQuestion $q) use ($screening, $overrides): void {
                EligibilityScreeningAnswer::create([
                    'screening_id' => $screening->id,
                    'question_code' => $q->code,
                    'answer' => $overrides[$q->code]
                        ?? ($q->disqualify_if_answer === null ? false : ! $q->disqualify_if_answer),
                    'created_at' => now(),
                ]);
            });

        return $screening->fresh();
    }

    /**
     * Put the donor in front of this counter by booking them in today.
     */
    private function bookToday(): DonationAppointment
    {
        return DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'appointment_datetime' => now(),
            'status' => AppointmentStatus::Scheduled,
        ]);
    }

    private function url(?EligibilityScreening $screening = null): string
    {
        return '/api/blood-center/donors/'.$this->donor->uuid.'/health-questionnaire'
            .($screening ? '?screening_id='.$screening->id : '');
    }

    // --- The scan carries a reference, never the answers -------------------

    public function test_the_scan_response_reports_the_questionnaire_without_any_answers(): void
    {
        $screening = $this->answerQuestionnaire();
        $raw = Str::random(40);

        DonorQrToken::factory()->create([
            'donor_id' => $this->donor->id,
            'screening_id' => $screening->id,
            'token_hash' => hash('sha256', $raw),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('data.health_questionnaire.available', true)
            ->assertJsonPath('data.health_questionnaire.screening_id', $screening->id)
            ->assertJsonPath('data.health_questionnaire.is_current_version', true)
            ->assertJsonPath('data.health_questionnaire.consent_captured', true);

        $body = $response->getContent();

        // Enough to draw the summary strip; nothing a nurse would read as a
        // health record.
        $this->assertStringNotContainsString('v2_ay_1', $body);
        $this->assertStringNotContainsString('Feeling healthy', $body);
        $this->assertStringNotContainsString('answer_label', $body);
    }

    public function test_a_credential_cannot_exist_without_the_questionnaire_behind_it(): void
    {
        // donor_qr_tokens.screening_id is NOT NULL, so a scanned code always
        // resolves to answers. This is why the counter can rely on the scan
        // reference rather than having to handle a credential from nowhere --
        // the invariant is in the schema, so it is asserted here rather than
        // guarded for in the read path.
        $this->expectException(QueryException::class);

        DonorQrToken::factory()->create([
            'donor_id' => $this->donor->id,
            'screening_id' => null,
            'token_hash' => hash('sha256', Str::random(40)),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);
    }

    // --- The document -------------------------------------------------------

    public function test_the_counter_reads_the_whole_questionnaire(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->bookToday();

        $response = $this->actingAs($this->staff)
            ->getJson($this->url($screening))
            ->assertOk()
            ->assertJsonPath('data.source', 'qr_token')
            ->assertJsonPath('data.question_version', 2)
            ->assertJsonPath('data.is_current_version', true)
            // All six, unlike the donor's own form. The counter is reading a
            // document, and the printed form carries the female-donors section
            // whoever is holding it -- shown as "Not applicable" rather than
            // silently absent, so a nurse can see it was considered.
            ->assertJsonCount(6, 'data.sections');

        $this->assertSame(29, $response->json('data.answered_count'));
        $this->assertSame(30, $response->json('data.question_count'));
        $this->assertSame(
            'Declared by the donor in the RedAgos portal. This is not the on-site screening.',
            $response->json('data.notice')
        );
    }

    public function test_section_one_a_carries_the_personal_data_and_names_what_is_missing(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->bookToday();

        $response = $this->actingAs($this->staff)
            ->getJson($this->url($screening))
            ->assertOk()
            ->assertJsonPath('data.personal_data.middle_name', 'Reyes')
            ->assertJsonPath('data.personal_data.civil_status_label', 'Single')
            ->assertJsonPath('data.personal_data.occupation', 'Teacher')
            ->assertJsonPath('data.personal_data.sex', 'male')
            // Derived from records, and labelled as such, so nobody reads it as
            // something the donor ticked.
            ->assertJsonPath('data.personal_data.donor_type', 'first_time')
            ->assertJsonPath('data.personal_data.donor_type_source', 'derived_from_records')
            ->assertJsonPath('data.personal_data.times_donated', 0);

        // Religion was never collected for this donor: it must be named as
        // absent, not printed as an empty line.
        $this->assertContains('religion', $response->json('data.personal_data.missing_fields'));
    }

    public function test_an_answer_is_labelled_for_the_screen_and_never_left_ambiguous(): void
    {
        $screening = $this->answerQuestionnaire(['v2_ev_5' => true]);
        $this->bookToday();

        $response = $this->actingAs($this->staff)
            ->getJson($this->url($screening))
            ->assertOk();

        $questions = collect($response->json('data.sections'))
            ->flatMap(fn (array $s): array => $s['questions'])
            ->keyBy('code');

        $this->assertSame('Yes', $questions['v2_ev_5']['answer_label']);
        $this->assertTrue($questions['v2_ev_5']['flagged']);
        $this->assertSame('No', $questions['v2_m12_1']['answer_label']);
        $this->assertFalse($questions['v2_m12_1']['flagged']);

        // Never put to this donor, so not "No".
        $this->assertSame('Not applicable', $questions['v2_fd_1']['answer_label']);
        $this->assertNull($questions['v2_fd_1']['answer']);

        $this->assertContains('v2_ev_5', $response->json('data.flagged_codes'));
    }

    public function test_the_acknowledgement_question_is_distinguished_from_the_risk_ones(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->bookToday();

        $questions = collect(
            $this->actingAs($this->staff)->getJson($this->url($screening))->assertOk()->json('data.sections')
        )->flatMap(fn (array $s): array => $s['questions'])->keyBy('code');

        $this->assertSame('acknowledgement', $questions['v2_ev_13']['kind']);
        $this->assertSame('risk', $questions['v2_ev_5']['kind']);
    }

    public function test_consent_is_shown_with_its_date_and_the_statements_agreed_to(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->bookToday();

        $this->actingAs($this->staff)
            ->getJson($this->url($screening))
            ->assertOk()
            ->assertJsonPath('data.consent.captured', true)
            ->assertJsonPath('data.consent.consented_on', now()->toDateString())
            ->assertJsonPath('data.consent.version', config('donor_consent.current'))
            ->assertJsonCount(5, 'data.consent.statements');
    }

    public function test_a_questionnaire_predating_consent_says_so_and_shows_no_date(): void
    {
        $screening = $this->answerQuestionnaire(screeningOverrides: [
            'consented_at' => null,
            'consent_version' => null,
        ]);
        $this->bookToday();

        $response = $this->actingAs($this->staff)
            ->getJson($this->url($screening))
            ->assertOk()
            ->assertJsonPath('data.consent.captured', false)
            ->assertJsonPath('data.consent.consented_on', null);

        // The worst available failure here would be rendering a gap as a
        // consent that was given, so the negative has to be explicit.
        $this->assertStringContainsString('No consent is on file', $response->json('data.consent.note'));
    }

    public function test_a_superseded_version_is_flagged_as_one(): void
    {
        $screening = EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'question_version' => 1,
            'consented_at' => null,
        ]);
        EligibilityScreeningAnswer::create([
            'screening_id' => $screening->id,
            'question_code' => 'gh_1',
            'answer' => true,
            'created_at' => now(),
        ]);
        $this->bookToday();

        $this->actingAs($this->staff)
            ->getJson($this->url($screening))
            ->assertOk()
            ->assertJsonPath('data.question_version', 1)
            ->assertJsonPath('data.is_current_version', false)
            ->assertJsonPath('data.consent.captured', false)
            // The old wording still resolves: deactivating or dropping v1 would
            // turn this into a list of bare codes.
            ->assertJsonPath('data.sections.0.questions.0.text', 'Are you currently feeling well and in good health today?');
    }

    // --- Who may read it ----------------------------------------------------

    public function test_another_department_cannot_read_a_questionnaire(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->bookToday();

        $laboratory = User::factory()->bloodCenterStaff($this->facility, Department::Testing)->create();

        $this->actingAs($laboratory)
            ->getJson($this->url($screening))
            ->assertForbidden();
    }

    public function test_a_facility_the_donor_has_not_presented_at_is_refused(): void
    {
        $screening = $this->answerQuestionnaire();

        // No appointment, no open donation, no scan here, no history: the
        // donor has not handed this centre anything.
        $this->actingAs($this->staff)
            ->getJson($this->url($screening))
            ->assertForbidden()
            ->assertJsonPath('code', 'donor_not_presented');
    }

    public function test_a_scan_at_this_counter_today_is_enough_to_read_it(): void
    {
        $screening = $this->answerQuestionnaire();
        $raw = Str::random(40);

        DonorQrToken::factory()->create([
            'donor_id' => $this->donor->id,
            'screening_id' => $screening->id,
            'token_hash' => hash('sha256', $raw),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk();

        $this->actingAs($this->staff)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.source', 'qr_token');
    }

    public function test_a_donor_with_no_questionnaire_at_all_is_reported_as_such(): void
    {
        $this->bookToday();

        $this->actingAs($this->staff)
            ->getJson($this->url())
            ->assertNotFound()
            ->assertJsonPath('code', 'questionnaire_not_found');
    }

    public function test_a_screening_belonging_to_another_donor_cannot_be_pinned(): void
    {
        $this->answerQuestionnaire();
        $this->bookToday();

        $other = User::factory()->donor()->create();
        $theirs = EligibilityScreening::factory()->create(['donor_id' => $other->id]);

        $this->actingAs($this->staff)
            ->getJson($this->url().'?screening_id='.$theirs->id)
            ->assertNotFound();
    }

    // --- Audit ---------------------------------------------------------------

    public function test_reading_a_questionnaire_is_audited_without_recording_any_answer(): void
    {
        $screening = $this->answerQuestionnaire(['v2_ev_5' => true]);
        $this->bookToday();

        $this->actingAs($this->staff)->getJson($this->url($screening))->assertOk();

        $log = AuditLog::where('action', 'collection.questionnaire_viewed')->firstOrFail();

        $this->assertSame($this->staff->id, $log->actor_id);
        $this->assertSame($this->facility->id, $log->context['facility_id']);
        $this->assertSame($screening->id, $log->context['screening_id']);

        // AuditLogger's own rule: identifiers and outcomes only.
        $context = json_encode($log->context);
        $this->assertStringNotContainsString('v2_ev_5', $context);
        $this->assertStringNotContainsString('answer', $context);
    }

    // --- Changing an answer during screening ---------------------------------

    private function openDonation(DonationStatus $status = DonationStatus::Registered, ?Facility $facility = null): Donation
    {
        return Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => ($facility ?? $this->facility)->id,
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, bool>  $answers
     */
    private function amend(EligibilityScreening $screening, array $answers, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->patchJson($this->url().'/answers', [
            'screening_id' => $screening->id,
            'answers' => collect($answers)->map(fn (bool $answer, string $code): array => ['code' => $code, 'answer' => $answer])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function question(TestResponse $response, string $code): array
    {
        return collect($response->json('data.sections'))
            ->flatMap(fn (array $section): array => $section['questions'])
            ->keyBy('code')
            ->get($code);
    }

    public function test_the_physician_changes_an_answer_and_the_donors_stays_on_record(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();

        $response = $this->amend($screening, ['v2_ev_5' => true])
            ->assertOk()
            ->assertJsonPath('data.computed_assessment', 'deferred');

        $question = $this->question($response, 'v2_ev_5');

        $this->assertTrue($question['answer']);
        $this->assertSame('Yes', $question['answer_label']);
        // The review marker reads the answer as it now stands.
        $this->assertTrue($question['flagged']);
        $this->assertContains('v2_ev_5', $response->json('data.flagged_codes'));

        $this->assertFalse($question['amendment']['donor_answer']);
        $this->assertSame('No', $question['amendment']['donor_answer_label']);
        $this->assertSame($this->staff->signatureName(), $question['amendment']['amended_by']);
        $this->assertNotNull($question['amendment']['amended_at']);

        // An answer nobody touched says nothing of the kind.
        $this->assertNull($this->question($response, 'v2_m12_1')['amendment']);

        $this->assertSame('deferred', $screening->fresh()->computed_result);
    }

    public function test_what_the_donor_declared_is_encrypted_like_the_answer(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();

        $this->amend($screening, ['v2_ev_5' => true])->assertOk();

        $raw = DB::table('eligibility_screening_answers')
            ->where('screening_id', $screening->id)
            ->where('question_code', 'v2_ev_5')
            ->value('original_answer');

        $this->assertNotSame('false', $raw);
        $this->assertSame('false', Crypt::decryptString($raw));
    }

    public function test_putting_an_answer_back_leaves_no_change_on_it(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();

        $this->amend($screening, ['v2_ev_5' => true])->assertOk();
        $response = $this->amend($screening, ['v2_ev_5' => false])
            ->assertOk()
            ->assertJsonPath('data.computed_assessment', 'eligible');

        $this->assertNull($this->question($response, 'v2_ev_5')['amendment']);
        $this->assertFalse($this->question($response, 'v2_ev_5')['answer']);
    }

    public function test_a_question_the_donor_left_blank_says_so_after_it_is_answered(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();
        EligibilityScreeningAnswer::where('screening_id', $screening->id)->where('question_code', 'v2_ev_5')->delete();

        // Blank is never a "No", so a further change cannot read as putting it back.
        foreach ([true, false] as $answer) {
            $amendment = $this->question($this->amend($screening, ['v2_ev_5' => $answer])->assertOk(), 'v2_ev_5')['amendment'];

            $this->assertNull($amendment['donor_answer']);
            $this->assertSame('Not answered', $amendment['donor_answer_label']);
        }
    }

    public function test_answers_cannot_be_changed_with_no_visit_open_here(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->bookToday();

        $this->amend($screening, ['v2_ev_5' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'questionnaire_not_amendable');

        // A visit open at another centre is not one at this counter.
        $this->openDonation(facility: Facility::factory()->approved()->create());

        $this->amend($screening, ['v2_ev_5' => true])->assertStatus(409);
    }

    public function test_answers_cannot_be_changed_once_the_screening_is_recorded(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation(DonationStatus::Screening);

        $this->amend($screening, ['v2_ev_5' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'questionnaire_not_amendable');

        $this->assertFalse(
            EligibilityScreeningAnswer::where('screening_id', $screening->id)->where('question_code', 'v2_ev_5')->sole()->answer
        );
    }

    public function test_a_lapsed_questionnaire_cannot_be_changed(): void
    {
        $screening = $this->answerQuestionnaire(screeningOverrides: ['valid_until' => now()->subDay()]);
        $this->openDonation();

        $this->amend($screening, ['v2_ev_5' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'questionnaire_not_amendable');
    }

    public function test_only_a_question_put_to_this_donor_can_be_changed(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();

        // v2_fd_1 is for female donors; this donor is male. One bad row refuses
        // the whole save rather than applying the rest.
        foreach (['v2_fd_1', 'nonsense'] as $code) {
            $this->amend($screening, ['v2_ev_5' => true, $code => true])
                ->assertStatus(422)
                ->assertJsonPath('code', 'question_not_amendable');
        }

        $this->assertDatabaseMissing('eligibility_screening_answers', ['screening_id' => $screening->id, 'question_code' => 'v2_fd_1']);
        $this->assertFalse(
            EligibilityScreeningAnswer::where('screening_id', $screening->id)->where('question_code', 'v2_ev_5')->sole()->answer
        );
    }

    public function test_another_donors_questionnaire_cannot_be_changed(): void
    {
        $this->answerQuestionnaire();
        $this->openDonation();

        $theirs = EligibilityScreening::factory()->create(['donor_id' => User::factory()->donor()->create()->id]);

        $this->amend($theirs, ['v2_ev_5' => true])
            ->assertNotFound()
            ->assertJsonPath('code', 'questionnaire_not_found');
    }

    public function test_only_the_physician_changes_answers(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();

        // The desk takes a walk-in's questionnaire, and still may not change one.
        foreach ([StaffRole::MedicalReceptionist, StaffRole::Phlebotomist] as $role) {
            $this->amend($screening, ['v2_ev_5' => true], User::factory()->bloodCenterStaff($this->facility, $role)->create())
                ->assertForbidden();
        }
    }

    public function test_changing_an_answer_is_audited_without_saying_which_or_what(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();

        // A save that changes nothing is not an event.
        $this->amend($screening, ['v2_ev_5' => false])->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'collection.questionnaire_amended']);

        $this->amend($screening, ['v2_ev_5' => true])->assertOk();

        $log = AuditLog::where('action', 'collection.questionnaire_amended')->sole();

        $this->assertSame($this->staff->id, $log->actor_id);
        $this->assertSame($screening->id, $log->context['screening_id']);
        $this->assertSame(1, $log->context['changed_count']);

        $context = json_encode($log->context);
        $this->assertStringNotContainsString('v2_ev_5', $context);
        $this->assertStringNotContainsString('answer', $context);
    }

    public function test_the_printed_form_says_an_answer_was_changed(): void
    {
        $screening = $this->answerQuestionnaire();
        $this->openDonation();

        $this->amend($screening, ['v2_ev_5' => true])->assertOk();

        $response = $this->actingAs($this->staff)
            ->getJson($this->url().'/print?screening_id='.$screening->id)
            ->assertOk();

        $question = $this->question($response, 'v2_ev_5');

        $this->assertTrue($question['answer']);
        $this->assertSame('No', $question['amendment']['donor_answer_label']);
        $this->assertArrayNotHasKey('flagged', $question);
    }
}
