<?php

namespace App\Service;

use App\Enums\EligibilityStatus;
use App\Models\DonorProfile;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\User;
use App\Repository\EligibilityRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
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
     * Get the questionnaire served to donors, without its disqualification flags.
     *
     * @return array<string, mixed>
     */
    public function questions(): array
    {
        $version = $this->currentVersion();
        $questions = $this->eligibilityRepository->questionsForVersion($version);

        return [
            'version' => $version,
            'sections' => $questions
                ->groupBy('section_key')
                ->map(fn ($sectionQuestions, $key): array => [
                    'key' => $key,
                    'title' => Str::headline($key),
                    'questions' => $sectionQuestions->map(fn ($question): array => [
                        'code' => $question->code,
                        'number' => $question->number,
                        'text' => $question->text,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
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
                ->lastCompletedDonationAt($profile->donor_id)?->toDateString(),
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
        $lastDonationAt = $this->eligibilityRepository->lastCompletedDonationAt($profile->donor_id);

        return [
            'eligibility_status' => $this->resolveStatus($latest)->value,
            'screening_date' => $latest?->screened_at?->toDateString(),
            'screening_valid_until' => $latest?->valid_until?->toDateString(),
            'deferral_reasons' => $latest?->deferral_reasons ?? [],
            'last_donation_date' => $lastDonationAt?->toDateString(),
            'next_eligible_date' => $this->evaluator->nextEligibleDate($lastDonationAt)?->toDateString(),
            'questionnaire_version' => $this->currentVersion(),
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

        if ((int) $payload['question_version'] !== $version) {
            throw new HttpResponseException(response()->json([
                'message' => 'The screening questionnaire has been updated. Please reload and answer the current version.',
                'code' => 'questionnaire_version_stale',
                'current_version' => $version,
            ], 409));
        }

        $questions = $this->eligibilityRepository->questionsForVersion($version);
        $answers = $this->normalizeAnswers($payload['answers']);
        $this->guardCompleteAnswers($questions, $answers);

        if (! $force) {
            $this->guardNoUnexpiredScreening($profile->donor_id);
        }

        $lastDonationAt = $this->eligibilityRepository->lastCompletedDonationAt($profile->donor_id);
        $weightKg = isset($payload['vitals']['weight']) ? (int) $payload['vitals']['weight'] : null;

        $evaluation = $this->evaluator->evaluate(
            $profile,
            $questions,
            $answers,
            $weightKg,
            $lastDonationAt
        );

        $screening = DB::transaction(function () use ($profile, $version, $payload, $answers, $evaluation, $weightKg): EligibilityScreening {
            $screening = $this->eligibilityRepository->createScreening([
                'donor_id' => $profile->donor_id,
                'question_version' => $version,
                'screened_at' => now(),
                'valid_until' => $this->evaluator->screeningValidUntil(),
                'result' => $evaluation['result'],
                'computed_result' => $evaluation['result'],
                'submitted_result' => $payload['result'] ?? null,
                'age_at_screening' => $evaluation['age'],
                'weight_kg' => $weightKg,
                'declared_last_donation_date' => $payload['vitals']['last_donation_date'] ?? null,
                'deferral_reasons' => $evaluation['deferral_reasons'],
            ]);

            $this->eligibilityRepository->storeAnswers($screening, $answers);
            $this->eligibilityRepository->revokeQrTokens($profile->donor_id);

            return $screening;
        });

        $this->auditLogger->record($user, 'eligibility.screening.created', $screening, [
            'result' => $evaluation['result'],
            'submitted_result' => $payload['result'] ?? null,
            'result_matched_submission' => $this->submissionMatches($payload['result'] ?? null, $evaluation['result']),
            'deferral_reason_codes' => array_column($evaluation['deferral_reasons'], 'code'),
        ]);

        $response = [
            'screening_id' => $screening->id,
            'result' => $screening->result->value,
            'screening_date' => $screening->screened_at->toDateString(),
            'screening_valid_until' => $screening->valid_until->toDateString(),
            'deferral_reasons' => $screening->deferral_reasons ?? [],
            'is_preliminary' => true,
            'notice' => 'This result is preliminary. Final eligibility is determined by blood centre staff at your appointment.',
        ];

        if ($screening->result === EligibilityStatus::Eligible && $user->hasVerifiedEmail()) {
            $response = array_merge($response, $this->issueQrToken($user, $screening));
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
            'eligibility_status' => $this->resolveStatus($screening)->value,
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
                'message' => 'A valid eligibility screening is required before a QR code can be issued.',
                'code' => 'screening_required',
            ], 403));
        }

        return $this->issueQrToken($user, $screening);
    }

    /**
     * Resolve the donor's eligibility state, ageing out lapsed screenings.
     */
    public function resolveStatus(?EligibilityScreening $screening): EligibilityStatus
    {
        if (! $screening) {
            return EligibilityStatus::Pending;
        }

        if ($screening->result === EligibilityStatus::Deferred) {
            return EligibilityStatus::Deferred;
        }

        if ($screening->result === EligibilityStatus::Eligible) {
            return $screening->valid_until->isFuture()
                ? EligibilityStatus::Eligible
                : EligibilityStatus::Expired;
        }

        return $screening->result;
    }

    /**
     * Get the donor's current eligibility state directly from their profile.
     */
    public function statusForProfile(DonorProfile $profile): EligibilityStatus
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
    private function issueQrToken(User $user, EligibilityScreening $screening): array
    {
        $plainToken = Str::random(64);
        $issuedAt = now();
        $expiresAt = $this->evaluator->qrValidUntil($issuedAt);

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
    private function guardCompleteAnswers($questions, array $answers): void
    {
        $missing = $questions->pluck('code')->diff(array_keys($answers))->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'answers' => ['Please answer every screening question before submitting.'],
            ]);
        }
    }

    /**
     * Reject a repeat screening while an unexpired one already stands.
     */
    private function guardNoUnexpiredScreening(int $donorId): void
    {
        $existing = $this->eligibilityRepository->currentValidScreening($donorId);

        if ($existing) {
            throw new HttpResponseException(response()->json([
                'message' => 'You already have a valid screening. Re-screen only if your health details have changed.',
                'code' => 'screening_already_valid',
                'screening_valid_until' => $existing->valid_until->toDateString(),
            ], 409));
        }
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

    private function submissionMatches(?string $submitted, string $computed): bool
    {
        return match ($submitted) {
            'eligible' => $computed === 'eligible',
            'not_eligible', 'deferred' => $computed === 'deferred',
            default => false,
        };
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
