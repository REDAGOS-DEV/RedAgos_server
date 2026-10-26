<?php

namespace App\Repository;

use App\Enums\ScreeningOutcome;
use App\Models\CounsellingReferral;
use App\Models\DonationScreening;
use App\Models\DonorDeferral;
use App\Support\OperationalDay;
use App\Support\StandingDeferral;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that answers "does this donor have a deferral with no end?".
 *
 * Three records can say so. The counter's screening officer can permanently
 * or indefinitely defer a donor; a reactive serology result permanently
 * defers them — recorded as the counselling referral the laboratory opens;
 * and staff can record a deferral by hand on the Deferrals page
 * (`donor_deferrals`, which alone can be lifted). Each source is the record
 * of its own decision and none is copied into another, so they cannot
 * disagree.
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
            $this->fromManual($donorId),
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
            // Lifted from the Deferrals page (project owner, 2026-10-25): the
            // screening keeps its outcome, but the deferral no longer stands.
            ->whereNull('lifted_at')
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
     * A reactive serology result, which permanently defers the donor at every
     * centre (project owner, 2026-10-26).
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

    /**
     * The most severe, then most recent, blocking deferral staff recorded by
     * hand and have not lifted.
     */
    private function fromManual(int $donorId): ?StandingDeferral
    {
        $deferrals = DonorDeferral::query()
            ->where('donor_id', $donorId)
            ->whereIn('outcome', [
                ScreeningOutcome::PermanentlyDeferred->value,
                ScreeningOutcome::IndefiniteDeferral->value,
            ])
            ->inForce()
            ->latest('deferred_on')
            ->latest('id')
            ->get(['id', 'outcome', 'deferred_on']);

        $deferral = $deferrals->firstWhere('outcome', ScreeningOutcome::PermanentlyDeferred)
            ?? $deferrals->first();

        return $deferral === null
            ? null
            : new StandingDeferral($deferral->outcome, $deferral->deferred_on);
    }

    /**
     * Each donor's deferral as the Donors list reads it, for many donors at once.
     *
     * A standing deferral, chosen exactly as standingDeferralFor() chooses it.
     * Failing that, a temporary deferral recorded by hand, until its end date.
     * Failing that, a temporary deferral when it is the donor's most recent
     * screening: until its Until date when the counter set one (project owner,
     * 2026-10-24), otherwise until the donor is screened again. That is the
     * cautious reading — it keeps them out of an eligibility reminder they may
     * not yet be fit for — and staff can still contact them one by one.
     *
     * Outcome and date only, like the counter's notice. Donors with neither
     * are absent from the result.
     *
     * @param  array<int, int>  $donorIds
     * @return array<int, StandingDeferral>
     */
    public function deferralsFor(array $donorIds): array
    {
        if ($donorIds === []) {
            return [];
        }

        $screenings = DonationScreening::query()
            ->join('donations', 'donations.id', '=', 'donation_screenings.donation_id')
            ->whereIn('donations.donor_id', $donorIds)
            ->whereNotNull('donation_screenings.outcome')
            ->orderByDesc('donation_screenings.screened_at')
            ->orderByDesc('donation_screenings.id')
            ->get(['donations.donor_id', 'donation_screenings.outcome', 'donation_screenings.screened_at', 'donation_screenings.deferred_until', 'donation_screenings.lifted_at'])
            ->groupBy('donor_id');

        $referrals = CounsellingReferral::query()
            ->whereIn('donor_id', $donorIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['donor_id', 'created_at'])
            ->groupBy('donor_id');

        $manual = DonorDeferral::query()
            ->whereIn('donor_id', $donorIds)
            ->inForce()
            ->orderByDesc('deferred_on')
            ->orderByDesc('id')
            ->get(['donor_id', 'outcome', 'deferred_on', 'ends_on'])
            ->groupBy('donor_id');

        $result = [];

        foreach ($donorIds as $donorId) {
            $mine = $screenings->get($donorId, collect());
            $myManual = $manual->get($donorId, collect());

            // A lifted one no longer stands; the latest screening still
            // decides whether a temporary one does.
            $standing = $mine->whereNull('lifted_at');
            $blocking = $standing->firstWhere('outcome', ScreeningOutcome::PermanentlyDeferred)
                ?? $standing->firstWhere('outcome', ScreeningOutcome::IndefiniteDeferral);

            $manualBlocking = $myManual->firstWhere('outcome', ScreeningOutcome::PermanentlyDeferred)
                ?? $myManual->firstWhere('outcome', ScreeningOutcome::IndefiniteDeferral);

            $candidates = array_filter([
                $blocking === null ? null : new StandingDeferral($blocking->outcome, $blocking->screened_at),
                ($referral = $referrals->get($donorId)?->first()) === null
                    ? null
                    : new StandingDeferral(ScreeningOutcome::PermanentlyDeferred, $referral->created_at),
                $manualBlocking === null ? null : new StandingDeferral($manualBlocking->outcome, $manualBlocking->deferred_on),
            ]);

            if ($candidates !== []) {
                usort($candidates, fn (StandingDeferral $a, StandingDeferral $b): int => $this->severity($b->outcome) <=> $this->severity($a->outcome)
                    ?: ($b->recordedAt?->getTimestamp() ?? 0) <=> ($a->recordedAt?->getTimestamp() ?? 0));

                $result[$donorId] = $candidates[0];

                continue;
            }

            // A temporary deferral recorded by hand holds until its end date,
            // whatever the last screening said.
            $manualTemporary = $myManual->firstWhere('outcome', ScreeningOutcome::TemporarilyDeferred);

            if ($manualTemporary !== null) {
                $result[$donorId] = new StandingDeferral($manualTemporary->outcome, $manualTemporary->deferred_on, $manualTemporary->ends_on);

                continue;
            }

            $latest = $mine->first();
            $until = $latest?->deferred_until;

            if ($latest?->outcome === ScreeningOutcome::TemporarilyDeferred
                && $latest->lifted_at === null
                && ($until === null || $until->toDateString() >= OperationalDay::todayAsDate())) {
                $result[$donorId] = new StandingDeferral($latest->outcome, $latest->screened_at, $until);
            }
        }

        return $result;
    }

    private function severity(ScreeningOutcome $outcome): int
    {
        return match ($outcome) {
            ScreeningOutcome::PermanentlyDeferred => 2,
            ScreeningOutcome::IndefiniteDeferral => 1,
            default => 0,
        };
    }
}
