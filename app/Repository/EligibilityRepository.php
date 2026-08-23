<?php

namespace App\Repository;

use App\Models\Donation;
use App\Models\DonorQrToken;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EligibilityRepository
{
    /**
     * Get the active questions making up a questionnaire version.
     *
     * @return Collection<int, EligibilityQuestion>
     */
    public function questionsForVersion(int $version): Collection
    {
        return EligibilityQuestion::forVersion($version)->get();
    }

    /**
     * Get the donor's most recent screening, whatever its outcome.
     */
    public function latestScreening(int $donorId): ?EligibilityScreening
    {
        return EligibilityScreening::where('donor_id', $donorId)
            ->latest('screened_at')
            ->latest('id')
            ->first();
    }

    /**
     * Get the donor's most recent screening that is still eligible and unexpired.
     */
    public function currentValidScreening(int $donorId): ?EligibilityScreening
    {
        return EligibilityScreening::where('donor_id', $donorId)
            ->currentlyValid()
            ->latest('screened_at')
            ->latest('id')
            ->first();
    }

    /**
     * Persist a screening record.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createScreening(array $payload): EligibilityScreening
    {
        return EligibilityScreening::create($payload);
    }

    /**
     * Persist the encrypted questionnaire answers for a screening.
     *
     * @param  array<string, bool>  $answers
     */
    public function storeAnswers(EligibilityScreening $screening, array $answers): void
    {
        foreach ($answers as $code => $answer) {
            EligibilityScreeningAnswer::create([
                'screening_id' => $screening->id,
                'question_code' => $code,
                'answer' => $answer,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Get the completion date of the donor's most recent completed donation.
     */
    public function lastCompletedDonationAt(int $donorId): ?Carbon
    {
        $donationDate = Donation::where('donor_id', $donorId)
            ->completed()
            ->max('donation_date');

        return $donationDate ? Carbon::parse($donationDate) : null;
    }

    /**
     * Get the donor's newest check-in token that is neither revoked nor expired.
     */
    public function usableQrToken(int $donorId): ?DonorQrToken
    {
        return DonorQrToken::where('donor_id', $donorId)
            ->usable()
            ->latest('issued_at')
            ->latest('id')
            ->first();
    }

    /**
     * Persist a check-in token record.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createQrToken(array $payload): DonorQrToken
    {
        return DonorQrToken::create($payload);
    }

    /**
     * Revoke every outstanding check-in token belonging to the donor.
     */
    public function revokeQrTokens(int $donorId): void
    {
        DonorQrToken::where('donor_id', $donorId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
