<?php

namespace App\Service;

use App\Enums\DeferralReason;
use App\Models\DonorProfile;
use App\Models\EligibilityQuestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides preliminary donor eligibility on the server.
 *
 * The browser computes its own provisional verdict and submits it, but that
 * value is only ever recorded for divergence detection: every rule below is
 * re-evaluated here from data the donor cannot forge. Age comes from the stored
 * birth date and the donation interval from completed donation records, never
 * from the numbers typed into the questionnaire.
 *
 * The outcome is preliminary. Final medical eligibility is determined by
 * authorised blood centre personnel at the point of donation.
 */
class EligibilityRuleEvaluator
{
    /**
     * Evaluate a questionnaire submission against every server-side rule.
     *
     * @param  Collection<int, EligibilityQuestion>  $questions
     * @param  array<string, bool>  $answers
     * @return array{result: string, deferral_reasons: array<int, array{code: string, message: string}>, age: ?int}
     */
    public function evaluate(
        DonorProfile $profile,
        Collection $questions,
        array $answers,
        ?int $weightKg,
        ?Carbon $lastCompletedDonationAt
    ): array {
        $reasons = [];
        $age = $this->ageFromBirthDate($profile->birth_date);

        if ($age !== null && $age < $this->minimumAge()) {
            $reasons[] = DeferralReason::BelowMinimumAge;
        }

        if ($weightKg !== null && $weightKg < $this->minimumWeight()) {
            $reasons[] = DeferralReason::BelowMinimumWeight;
        }

        if ($this->isWithinDonationInterval($lastCompletedDonationAt)) {
            $reasons[] = DeferralReason::BelowMinimumInterval;
        }

        if ($this->hasDisqualifyingAnswer($questions, $answers)) {
            $reasons[] = DeferralReason::QuestionnaireResponse;
        }

        return [
            'result' => $reasons === [] ? 'eligible' : 'deferred',
            'deferral_reasons' => array_map(
                fn (DeferralReason $reason): array => [
                    'code' => $reason->value,
                    'message' => $reason->message(),
                ],
                $reasons
            ),
            'age' => $age,
        ];
    }

    /**
     * Determine the earliest date the donor may donate again.
     */
    public function nextEligibleDate(?Carbon $lastCompletedDonationAt): ?Carbon
    {
        return $lastCompletedDonationAt?->copy()->addDays($this->intervalDays())->startOfDay();
    }

    /**
     * Determine whether the donation interval has not yet elapsed.
     */
    public function isWithinDonationInterval(?Carbon $lastCompletedDonationAt): bool
    {
        if ($lastCompletedDonationAt === null) {
            return false;
        }

        return $lastCompletedDonationAt->copy()->addDays($this->intervalDays())->isFuture();
    }

    /**
     * Get the moment a screening taken now would stop being valid.
     */
    public function screeningValidUntil(?Carbon $screenedAt = null): Carbon
    {
        return ($screenedAt?->copy() ?? now())->addDays(
            (int) config('donation.screening_validity_days')
        );
    }

    /**
     * Get the moment a check-in token issued now would expire.
     */
    public function qrValidUntil(?Carbon $issuedAt = null): Carbon
    {
        return ($issuedAt?->copy() ?? now())->addDays(
            (int) config('donation.qr_validity_days')
        );
    }

    /**
     * Calculate a whole-year age from a stored birth date.
     */
    public function ageFromBirthDate(?Carbon $birthDate): ?int
    {
        return $birthDate?->diffInYears(now());
    }

    /**
     * Determine whether any answer trips its question's disqualification flag.
     *
     * @param  Collection<int, EligibilityQuestion>  $questions
     * @param  array<string, bool>  $answers
     */
    private function hasDisqualifyingAnswer(Collection $questions, array $answers): bool
    {
        return $questions->contains(function (EligibilityQuestion $question) use ($answers): bool {
            if ($question->disqualify_if_answer === null) {
                return false;
            }

            return array_key_exists($question->code, $answers)
                && $answers[$question->code] === $question->disqualify_if_answer;
        });
    }

    private function minimumAge(): int
    {
        return (int) config('donation.min_age_years');
    }

    private function minimumWeight(): int
    {
        return (int) config('donation.min_weight_kg');
    }

    private function intervalDays(): int
    {
        return (int) config('donation.interval_days');
    }
}
