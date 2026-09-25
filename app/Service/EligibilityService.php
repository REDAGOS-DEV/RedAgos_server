<?php

namespace App\Service;

use App\Enums\EligibilityStatus;
use App\Enums\QuestionnaireStatus;
use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\User;
use App\Repository\EligibilityRepository;
use App\Support\AppointmentScreeningWindow;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EligibilityService
{
    public function __construct(
        private readonly EligibilityRepository $eligibilityRepository,
        private readonly EligibilityRuleEvaluator $evaluator,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Get the questionnaire served to donors, without its review markers.
     *
     * `disqualify_if_answer` is withheld here and surfaced only to blood centre
     * staff. The donor is not told which answers draw a second look, which is
     * the whole point of the centre being the one to decide.
     *
     * @return array<string, mixed>
     */
    public function questions(User $user): array
    {
        $profile = $this->requireDonorProfile($user);

        $this->guardScreeningWindowOpen($profile);

        $version = $this->currentVersion();
        $questions = $this->guardVersionIsSeeded(
            $this->eligibilityRepository->questionsForVersion($version),
            $version
        );

        $gender = $profile->gender;

        return [
            'version' => $version,
            'sections' => $questions
                // Applicability is decided here, from the donor's stored
                // gender, so the client never gets to choose who is asked what.
                // A question that does not apply is omitted rather than
                // answered false, which would record a clinical falsehood and
                // render a meaningless "No" at the counter.
                ->filter(fn (EligibilityQuestion $question): bool => $question->appliesToGender($gender))
                ->groupBy('section_key')
                ->map(fn ($sectionQuestions, $key): array => [
                    'key' => $key,
                    'number' => $sectionQuestions->first()->section_number,
                    // The DOH headings are transcribed data. Str::headline()
                    // turns 'past_12_months' into 'Past 12 Months', which is not
                    // what the form says, so it is only the fallback for v1.
                    'title' => $sectionQuestions->first()->section_title ?? Str::headline($key),
                    'questions' => $sectionQuestions->map(fn (EligibilityQuestion $question): array => [
                        'code' => $question->code,
                        'number' => $question->number,
                        'text' => $question->text,
                        'kind' => $question->kind?->value ?? 'risk',
                        'required' => $question->isRequiredForGender($gender),
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
            'consent' => $this->consentBlock(),
        ];
    }

    /**
     * The informed consent statements the donor must accept, and their version.
     *
     * Served alongside the questions rather than from its own endpoint, so the
     * version the client echoes back is provably the one it rendered.
     *
     * @return array<string, mixed>
     */
    private function consentBlock(): array
    {
        $version = $this->currentConsentVersion();

        return [
            'version' => $version,
            'statements' => $this->consentStatements($version),
        ];
    }

    /**
     * Get server-derived hints that pre-fill the screening form.
     *
     * @return array<string, mixed>
     */
    public function prefill(User $user): array
    {
        $profile = $this->requireDonorProfile($user);

        return [
            'blood_type' => $profile->bloodType?->code,
            'age' => $this->evaluator->ageFromBirthDate($profile->birth_date),
            'last_donation_date' => $this->eligibilityRepository
                ->lastBloodDrawnAt($profile->donor_id)?->toDateString(),
        ];
    }

    /**
     * Get the donor's current eligibility state and latest screening summary.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $profile = $this->requireDonorProfile($user);
        $latest = $this->eligibilityRepository->latestScreening($profile->donor_id);
        $lastDonationAt = $this->eligibilityRepository->lastBloodDrawnAt($profile->donor_id);
        $appointment = $this->activeAppointmentFor($profile);

        return [
            // Says whether the questionnaire has been answered and still
            // stands -- not whether the donor may give blood, which is the
            // blood centre's to say.
            'questionnaire_status' => $this->resolveStatus($latest)->value,
            'screening_date' => $latest?->screened_at?->toDateString(),
            'screening_valid_until' => $latest?->valid_until?->toDateString(),
            'consented_on' => $latest?->consented_at?->toDateString(),
            'last_donation_date' => $lastDonationAt?->toDateString(),
            'next_eligible_date' => $this->evaluator->nextEligibleDate($lastDonationAt)?->toDateString(),
            'questionnaire_version' => $this->currentVersion(),
            'screening_question_version' => $latest?->question_version,
            // A donor holding an answered but superseded questionnaire is
            // nudged to answer the current one, never forced.
            're_screen_recommended' => $latest !== null
                && (int) $latest->question_version < $this->currentVersion(),
            'appointment' => $appointment === null ? null : [
                'id' => $appointment->id,
                'appointment_datetime' => $appointment->appointment_datetime?->toISOString(),
                'screening_window_opens_on' => AppointmentScreeningWindow::opensOn($appointment),
                'screening_window_open' => AppointmentScreeningWindow::contains($appointment),
            ],
        ];
    }

    /**
     * Score a questionnaire submission on the server and record the outcome.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitScreening(User $user, array $payload, bool $force = false): array
    {
        $profile = $this->requireDonorProfile($user);
        $version = $this->currentVersion();

        $appointment = $this->guardScreeningWindowOpen($profile);

        if ((int) $payload['question_version'] !== $version) {
            throw new HttpResponseException(response()->json([
                'message' => 'The screening questionnaire has been updated. Please reload and answer the current version.',
                'code' => 'questionnaire_version_stale',
                'current_version' => $version,
            ], 409));
        }

        $this->guardConsent($payload);

        $questions = $this->guardVersionIsSeeded(
            $this->eligibilityRepository->questionsForVersion($version),
            $version
        );

        $answers = $this->normalizeAnswers($payload['answers']);
        $this->guardCompleteAnswers($questions, $answers, $profile->gender);

        if (! $force) {
            $this->guardNoUnexpiredScreening($profile->donor_id);
        }

        $lastDonationAt = $this->eligibilityRepository->lastBloodDrawnAt($profile->donor_id);
        $weightKg = isset($payload['vitals']['weight']) ? (int) $payload['vitals']['weight'] : null;

        // The three objective thresholds still refuse a submission. The answers
        // themselves never do -- see EligibilityRuleEvaluator's docblock.
        $this->guardObjectiveThresholds($profile, $weightKg, $lastDonationAt);

        $consentVersion = $payload['consent']['version'];

        $screening = DB::transaction(function () use (
            $profile, $version, $payload, $answers, $questions, $weightKg, $consentVersion
        ): EligibilityScreening {
            $screening = $this->eligibilityRepository->createScreening([
                'donor_id' => $profile->donor_id,
                'question_version' => $version,
                'screened_at' => now(),
                'valid_until' => $this->evaluator->screeningValidUntil(),
                // Answered, awaiting the blood centre's decision. RedAgos does
                // not rule on a donor's own answers.
                'result' => EligibilityStatus::Pending,
                // Advisory only, for the counter. Never shown to the donor.
                'computed_result' => $this->evaluator->assessAnswers($questions, $answers),
                'age_at_screening' => $this->evaluator->ageFromBirthDate($profile->birth_date),
                // Snapshot, for the same reason the age is one: a later profile
                // edit must not change what this record says was asked.
                'gender_at_screening' => $profile->gender,
                'weight_kg' => $weightKg,
                'declared_last_donation_date' => $payload['vitals']['last_donation_date'] ?? null,
                'declared_last_donation_venue' => $payload['vitals']['last_donation_venue'] ?? null,
                'last_menstrual_period' => $payload['vitals']['last_menstrual_period'] ?? null,
                'deferral_reasons' => [],
                // Computed here, never taken from the request.
                'consented_at' => now(),
                'consent_version' => $consentVersion,
                'consent_text_hash' => $this->consentTextHash($consentVersion),
            ]);

            $this->eligibilityRepository->storeAnswers($screening, $answers);
            $this->eligibilityRepository->revokeQrTokens($profile->donor_id);

            return $screening;
        });

        $this->auditLogger->record($user, 'eligibility.screening.created', $screening, [
            'question_version' => $version,
            'consent_version' => $consentVersion,
            'computed_result' => $screening->computed_result,
            'flagged_count' => count($this->evaluator->flaggedAnswers($questions, $answers)),
            'appointment_id' => $appointment?->id,
        ]);

        $response = [
            'screening_id' => $screening->id,
            'screening_date' => $screening->screened_at->toDateString(),
            'screening_valid_until' => $screening->valid_until->toDateString(),
            'consented_on' => $screening->consented_at->toDateString(),
            'notice' => 'Your questionnaire has been submitted. The blood centre will review your answers when you arrive.',
        ];

        // A complete, in-window questionnaire always mints a credential. What
        // the answers say is the centre's to weigh, not this method's.
        if ($user->hasVerifiedEmail()) {
            $response = array_merge($response, $this->issueQrToken($user, $screening, $appointment));
        }

        return $response;
    }

    /**
     * Get the donor's check-in credential and the details printed alongside it.
     *
     * @return array<string, mixed>
     */
    public function qrCode(User $user): array
    {
        $profile = $this->requireDonorProfile($user);
        $screening = $this->eligibilityRepository->latestScreening($profile->donor_id);
        $token = $this->eligibilityRepository->usableQrToken($profile->donor_id);

        $this->auditLogger->record($user, 'donor.qr_code.viewed', $screening, [
            'has_usable_token' => $token !== null,
        ]);

        return [
            'profile' => [
                'full_name' => trim($user->first_name.' '.$user->last_name),
                'donor_id' => $this->donorCode($profile->donor_id),
                'blood_type' => $profile->bloodType?->code,
                'screening_date' => $screening?->screened_at?->toDateString(),
                'screening_valid_until' => $screening?->valid_until?->toDateString(),
                'qr_token' => null,
            ],
            'questionnaire_status' => $this->resolveStatus($screening)->value,
            'qr_valid_until' => $token?->expires_at?->toDateString(),
            'qr_valid_days' => (int) config('donation.qr_validity_days'),
            'has_active_token' => $token !== null,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    /**
     * Mint a replacement check-in token without requiring a fresh screening.
     *
     * @return array<string, mixed>
     */
    public function refreshQrCode(User $user): array
    {
        $profile = $this->requireDonorProfile($user);
        $this->guardEmailVerified($user);

        $screening = $this->eligibilityRepository->currentValidScreening($profile->donor_id);

        if (! $screening) {
            throw new HttpResponseException(response()->json([
                'message' => 'Please complete your health questionnaire before requesting a QR code.',
                'code' => 'screening_required',
            ], 403));
        }

        // Capped against the booking as well, so refreshing cannot be used to
        // outlive the visit the questionnaire was answered for.
        return $this->issueQrToken($user, $screening, $this->activeAppointmentFor($profile));
    }

    /**
     * Resolve the donor's eligibility state, ageing out lapsed screenings.
     */
    public function resolveStatus(?EligibilityScreening $screening): QuestionnaireStatus
    {
        if (! $screening) {
            return QuestionnaireStatus::NotAnswered;
        }

        // Historical rows from before RedAgos stopped ruling on a donor's own
        // answers may still hold `eligible` or `deferred`. Either way the
        // questionnaire was answered, which is all this reports now.
        return $screening->valid_until->isFuture()
            ? QuestionnaireStatus::Answered
            : QuestionnaireStatus::Expired;
    }

    /**
     * Get the donor's current eligibility state directly from their profile.
     */
    public function statusForProfile(DonorProfile $profile): QuestionnaireStatus
    {
        return $this->resolveStatus(
            $this->eligibilityRepository->latestScreening($profile->donor_id)
        );
    }

    /**
     * Issue a new check-in token, superseding any outstanding one.
     *
     * @return array<string, mixed>
     */
    private function issueQrToken(
        User $user,
        EligibilityScreening $screening,
        ?DonationAppointment $appointment = null
    ): array {
        $plainToken = Str::random(64);
        $issuedAt = now();
        $expiresAt = $this->evaluator->qrValidUntil($issuedAt);

        // A credential minted for a booked visit must not outlive it. Without
        // this a donor who answers the day before a Monday appointment holds a
        // working QR for a fortnight, long after the questionnaire behind it
        // stopped describing the visit it was issued for.
        if ($appointment !== null) {
            $closesAt = AppointmentScreeningWindow::closesAt($appointment);

            if ($closesAt->lessThan($expiresAt)) {
                $expiresAt = Carbon::parse($closesAt);
            }
        }

        $token = DB::transaction(function () use ($screening, $plainToken, $issuedAt, $expiresAt) {
            $this->eligibilityRepository->revokeQrTokens($screening->donor_id);

            return $this->eligibilityRepository->createQrToken([
                'donor_id' => $screening->donor_id,
                'screening_id' => $screening->id,
                'token_hash' => hash('sha256', $plainToken),
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
            ]);
        });

        $this->auditLogger->record($user, 'donor.qr_code.issued', $token, [
            'screening_id' => $screening->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return [
            'qr_token' => $plainToken,
            'qr_valid_until' => $expiresAt->toDateString(),
            'qr_valid_days' => (int) config('donation.qr_validity_days'),
        ];
    }

    /**
     * Reduce the submitted answer list to a code-keyed map of booleans.
     *
     * @param  array<int, array{code: string, answer: mixed}>  $answers
     * @return array<string, bool>
     */
    private function normalizeAnswers(array $answers): array
    {
        $normalized = [];

        foreach ($answers as $answer) {
            $normalized[$answer['code']] = filter_var($answer['answer'], FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }

    /**
     * Reject a submission that does not answer every question in the version.
     *
     * @param  Collection<int, EligibilityQuestion>  $questions
     * @param  array<string, bool>  $answers
     */
    private function guardCompleteAnswers($questions, array $answers, ?string $gender = null): void
    {
        // Only what this donor was actually asked. Question 5 is put to female
        // donors; demanding it of everyone would force the client to send a
        // clinical falsehood to get past this check.
        $required = $questions
            ->filter(fn (EligibilityQuestion $question): bool => $question->isRequiredForGender($gender))
            ->pluck('code');

        $missing = $required->diff(array_keys($answers))->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'answers' => ['Please answer every screening question before submitting.'],
            ]);
        }
    }

    /**
     * Refuse to serve or record a questionnaire version that has no questions.
     *
     * config('donation.questionnaire_version') is env-driven, so it can reach a
     * version whose rows have not been seeded. Without this guard that state is
     * silent and dangerous: zero questions are served, guardCompleteAnswers()
     * passes on an empty answer set, and every submission is recorded as a
     * fully answered questionnaire that nobody was ever asked.
     *
     * @param  Collection<int, EligibilityQuestion>  $questions
     * @return Collection<int, EligibilityQuestion>
     */
    private function guardVersionIsSeeded(Collection $questions, int $version): Collection
    {
        if ($questions->isNotEmpty()) {
            return $questions;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'The health questionnaire is temporarily unavailable. Please try again shortly.',
            'code' => 'questionnaire_unavailable',
            'version' => $version,
        ], 503));
    }

    /**
     * Refuse a questionnaire answered too far ahead of a booked appointment.
     *
     * Refusing here rather than only at submission is the point: a donor who
     * opens the form three weeks early is told the date it opens, instead of
     * answering thirty questions that cannot be counted.
     *
     * A donor with no active appointment is not subject to the window at all --
     * walk-in donors answer whenever they like, governed by
     * screening_validity_days as before.
     */
    private function guardScreeningWindowOpen(DonorProfile $profile): ?DonationAppointment
    {
        $appointment = $this->activeAppointmentFor($profile);

        if ($appointment === null || ! AppointmentScreeningWindow::isTooEarly($appointment)) {
            return $appointment;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Your health questionnaire opens on '
                .AppointmentScreeningWindow::opensAt($appointment)->format('j F Y')
                .', the day before your appointment.',
            'code' => 'screening_window_not_open',
            'window_opens_on' => AppointmentScreeningWindow::opensOn($appointment),
            'appointment_datetime' => $appointment->appointment_datetime?->toISOString(),
        ], 409));
    }

    /**
     * Refuse a submission that breaches an objective threshold.
     *
     * Age, weight and the donation interval are arithmetic on facts, not
     * readings of the donor's answers, so they still stop a submission. They
     * are reported as plain statements of the threshold and never in the
     * language of a health verdict.
     */
    private function guardObjectiveThresholds(
        DonorProfile $profile,
        ?int $weightKg,
        ?Carbon $lastBloodDrawnAt
    ): void {
        $violations = $this->evaluator->objectiveViolations($profile, $weightKg, $lastBloodDrawnAt);

        if ($violations === []) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $violations[0]['message'],
            'code' => 'threshold_not_met',
            'reasons' => $violations,
        ], 422));
    }

    /**
     * Refuse consent given against wording that is no longer current.
     *
     * A donor sitting on a page cached before the statements changed must not
     * be recorded as having agreed to the withdrawn text.
     *
     * @param  array<string, mixed>  $payload
     */
    private function guardConsent(array $payload): void
    {
        $current = $this->currentConsentVersion();

        if (($payload['consent']['version'] ?? null) === $current) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'The consent statements have been updated. Please reload and read the current version.',
            'code' => 'consent_version_stale',
            'current_version' => $current,
        ], 409));
    }

    /**
     * The donor's next active booking, if they hold one.
     */
    private function activeAppointmentFor(DonorProfile $profile): ?DonationAppointment
    {
        return $this->eligibilityRepository->nextActiveAppointment($profile->donor_id);
    }

    private function currentConsentVersion(): string
    {
        return (string) config('donor_consent.current');
    }

    /**
     * The statements belonging to a consent version.
     *
     * @return array<int, string>
     */
    private function consentStatements(string $version): array
    {
        return (array) config('donor_consent.versions.'.$version.'.statements', []);
    }

    /**
     * A digest of the exact wording a donor agreed to.
     *
     * The version key alone is not proof: a config file can be edited without
     * anyone bumping the version. This pins the revision that was on screen.
     */
    private function consentTextHash(string $version): string
    {
        return hash('sha256', implode("\n", $this->consentStatements($version)));
    }

    /**
     * Reject a repeat screening while an unexpired one already stands.
     */
    private function guardNoUnexpiredScreening(int $donorId): void
    {
        $existing = $this->eligibilityRepository->currentValidScreening($donorId);

        if (! $existing) {
            return;
        }

        // A donor holding a superseded version is answering a different form,
        // not repeating themselves. This guard exists to stop pointless
        // duplicates, so it must not stand in the way of that -- otherwise the
        // only route onto the current questionnaire is the "re-screen anyway"
        // override, which reads like ignoring a warning. `force` keeps its real
        // meaning: my health has changed since I last answered.
        if ((int) $existing->question_version < $this->currentVersion()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'You have already answered the current questionnaire. Answer it again only if your health details have changed.',
            'code' => 'screening_already_valid',
            'screening_valid_until' => $existing->valid_until->toDateString(),
        ], 409));
    }

    /**
     * Reject an action that requires a confirmed email address.
     */
    private function guardEmailVerified(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Please verify your email address before requesting a QR code.',
            'code' => 'email_unverified',
        ], 403));
    }

    /**
     * Load the donor profile the API requires, or fail with a validation error.
     */
    private function requireDonorProfile(User $user): DonorProfile
    {
        $profile = $user->donorProfile()->with('bloodType')->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        return $profile;
    }

    private function currentVersion(): int
    {
        return (int) config('donation.questionnaire_version');
    }

    private function donorCode(int $donorId): string
    {
        return 'DONOR-'.str_pad((string) $donorId, 6, '0', STR_PAD_LEFT);
    }
}
