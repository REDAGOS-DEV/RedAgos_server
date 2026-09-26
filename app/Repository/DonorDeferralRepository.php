<?php

namespace App\Repository;

use App\Enums\ScreeningOutcome;
use App\Models\CounsellingReferral;
use App\Models\DonationScreening;
use App\Support\StandingDeferral;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that answers "does this donor have a deferral with no end?".
 *
 * Two records can say so. The counter's screening officer can permanently or
 * indefinitely defer a donor, and a reactive serology result permanently
 * defers them — recorded as the counselling referral the laboratory opens.
 * There is no separate deferrals table: each source is already the record of
 * the decision, and a copy would be one more thing that could disagree.
 *
 * Deliberately not limited to one facility. A donor permanently deferred at
 * one centre is permanently deferred, and the counter that has never met them
 * is exactly the one that needs telling.
 */
class DonorDeferralRepository
{
    /**
     * The donor's standing deferral, if any.
     *
     * The more severe wins, then the more recent: a permanent deferral is never
     * hidden behind a newer indefinite one, which may yet be lifted.
     */
    public function standingDeferralFor(int $donorId): ?StandingDeferral
    {
        $candidates = array_filter([
            $this->fromScreening($donorId),
            $this->fromLaboratory($donorId),
        ]);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (StandingDeferral $a, StandingDeferral $b): int {
            $severity = $this->severity($b->outcome) <=> $this->severity($a->outcome);

            return $severity !== 0
                ? $severity
                : ($b->recordedAt?->getTimestamp() ?? 0) <=> ($a->recordedAt?->getTimestamp() ?? 0);
        });

        return $candidates[0];
    }

    /**
     * The most severe, then most recent, blocking screening outcome.
     *
     * `donation_screenings` has no `donor_id` — a screening belongs to a
     * donation, and the donation is what belongs to a donor — so the question
     * goes through the relation. `donations.donor_id` is indexed, and this runs
     * once per scan.
     */
    private function fromScreening(int $donorId): ?StandingDeferral
    {
        $screenings = DonationScreening::query()
            ->whereIn('outcome', [
                ScreeningOutcome::PermanentlyDeferred->value,
                ScreeningOutcome::IndefiniteDeferral->value,
            ])
            ->whereHas('donation', fn (Builder $q) => $q->where('donor_id', $donorId))
            ->latest('screened_at')
            ->latest('id')
            ->get(['id', 'outcome', 'screened_at']);

        $screening = $screenings->firstWhere('outcome', ScreeningOutcome::PermanentlyDeferred)
            ?? $screenings->first();

        return $screening === null
            ? null
            : new StandingDeferral($screening->outcome, $screening->screened_at);
    }

    /**
     * A reactive serology result, which permanently defers the donor.
     */
    private function fromLaboratory(int $donorId): ?StandingDeferral
    {
        $referral = CounsellingReferral::query()
            ->where('donor_id', $donorId)
            ->latest('created_at')
            ->latest('id')
            ->first(['id', 'created_at']);

        return $referral === null
            ? null
            : new StandingDeferral(ScreeningOutcome::PermanentlyDeferred, $referral->created_at);
    }

    private function severity(ScreeningOutcome $outcome): int
    {
        return $outcome === ScreeningOutcome::PermanentlyDeferred ? 2 : 1;
    }
}
