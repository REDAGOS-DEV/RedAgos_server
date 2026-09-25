<?php

namespace App\Service;

use App\Enums\DeferralReason;
use App\Models\DonorProfile;
use App\Models\EligibilityQuestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The server-side rules that stand between a donor and a recorded questionnaire.
 *
 * There are two kinds, and keeping them apart is the point of this class.
 *
 * The first kind are OBJECTIVE THRESHOLDS -- age, weight, and the interval
 * since the last draw. They are arithmetic on facts the donor cannot forge:
 * age comes from the stored birth date, and the interval from completed
 * donation records, never from numbers typed into the questionnaire. These
 * still refuse a submission, and they are stated plainly ("you cannot donate
 * again until 14 November") rather than as a health verdict.
 *
 * The second kind is the QUESTIONNAIRE ITSELF, and it decides nothing at all.
 * RedAgos does not read a donor's answers and rule on them -- it records what
 * was asked and answered, and the blood centre decides at the counter from its
 * own assessment. The form says as much on its face: "A 'YES' answer may not
 * necessarily exclude you from blood donation." assessAnswers() and
 * flaggedAnswers() therefore feed two things only: the advisory
 * computed_result, and the rows the counter's questionnaire drawer highlights
 * for a nurse's attention. Neither is ever shown to the donor.
 */
class EligibilityRuleEvaluator
{
    /**
     * The objective thresholds a submission must clear, if any are breached.
     *
     * Age cannot realistically fail -- registration already enforces 18 -- and
     * the interval rarely can, because booking enforces it. Weight is the one a
     * donor will actually meet.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function objectiveViolations(
        DonorProfile $profile,
        ?int $weightKg,
        ?Carbon $lastBloodDrawnAt
    ): array {
        $reasons = [];

        $age = $this->ageFromBirthDate($profile->birth_date);

        if ($age !== null && $age < $this->minimumAge()) {
            $reasons[] = DeferralReason::BelowMinimumAge;
        }

        if ($weightKg !== null && $weightKg < $this->minimumWeight()) {
            $reasons[] = DeferralReason::BelowMinimumWeight;
        }

        if ($this->isWithinDonationInterval($lastBloodDrawnAt)) {
            $reasons[] = DeferralReason::BelowMinimumInterval;
        }

        return array_map(
            fn (DeferralReason $reason): array => [
                'code' => $reason->value,
                'message' => $reason->message(),
            ],
            $reasons
        );
    }

    /**
     * The server's own advisory read of the answers, for computed_result.
     *
     * Recorded so the blood centre can see what the system would have made of
     * the answers, and so the change is auditable if the questionnaire ever
     * regains a decisive role. It never reaches the donor and never refuses
     * anything.
     *
     * @param  Collection<int, EligibilityQuestion>  $questions
     * @param  array<string, bool>  $answers
     */
    public function assessAnswers(Collection $questions, array $answers): string
    {
        return $this->flaggedAnswers($questions, $answers) === [] ? 'eligible' : 'deferred';
    }

    /**
     * The codes of the answers that trip their question's review marker.
     *
     * What the counter's drawer highlights. An unanswered code is never
     * flagged -- a question that was not asked has no answer to disagree with.
     *
     * @param  Collection<int, EligibilityQuestion>  $questions
     * @param  array<string, bool>  $answers
     * @return array<int, string>
     */
    public function flaggedAnswers(Collection $questions, array $answers): array
    {
        return $questions
            ->filter(function (EligibilityQuestion $question) use ($answers): bool {
                if ($question->disqualify_if_answer === null) {
                    return false;
                }

                return array_key_exists($question->code, $answers)
                    && $answers[$question->code] === $question->disqualify_if_answer;
            })
            ->pluck('code')
            ->values()
            ->all();
    }

    /**
     * Determine the earliest date the donor may donate again.
     */
    public function nextEligibleDate(?Carbon $lastBloodDrawnAt): ?Carbon
    {
        return $lastBloodDrawnAt?->copy()->addDays($this->intervalDays())->startOfDay();
    }

    /**
     * Determine whether the donation interval has not yet elapsed.
     */
    public function isWithinDonationInterval(?Carbon $lastBloodDrawnAt): bool
    {
        if ($lastBloodDrawnAt === null) {
            return false;
        }

        return $lastBloodDrawnAt->copy()->addDays($this->intervalDays())->isFuture();
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
