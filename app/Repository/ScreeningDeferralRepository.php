<?php

namespace App\Repository;

use App\Enums\ScreeningOutcome;
use App\Models\DonationScreening;
use App\Support\OperationalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * One centre's screening deferrals (the counter's, and a drive's paper
 * records') as the Deferrals page lists them beside the ones recorded there
 * (project owner, 2026-10-25).
 *
 * In force as DonorDeferralRepository reads them: a permanent or indefinite
 * one always; a temporary one until its Until date, or, with none (a paper
 * record, or one from before the counter set it), until the donor is screened
 * again. It can be lifted from the page as a deferral recorded there can
 * (project owner, 2026-10-25); the screening keeps its outcome.
 */
class ScreeningDeferralRepository
{
    private const WITH = [
        'donation.donorProfile.donor',
        'donation.donorProfile.bloodType',
        'recorder',
        'lifter',
    ];

    /**
     * Filters: search (donor name or DONOR- code, a DON- code, a deferral
     * code exactly, or words of the reason) and status (active, expired or
     * lifted).
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $facilityId, array $filters): Builder
    {
        $today = OperationalDay::todayAsDate();
        $status = $filters['status'] ?? null;

        return DonationScreening::query()
            ->join('donations', 'donations.id', '=', 'donation_screenings.donation_id')
            ->where('donation_screenings.facility_id', $facilityId)
            ->whereIn('donation_screenings.outcome', [
                ScreeningOutcome::TemporarilyDeferred->value,
                ScreeningOutcome::PermanentlyDeferred->value,
                ScreeningOutcome::IndefiniteDeferral->value,
            ])
            ->when($status === 'lifted', fn (Builder $q) => $q->whereNotNull('donation_screenings.lifted_at'))
            ->when($status === 'active', fn (Builder $q) => $q->whereNull('donation_screenings.lifted_at')->where(fn (Builder $w) => $this->inForce($w, $today)))
            ->when($status === 'expired', fn (Builder $q) => $q->whereNull('donation_screenings.lifted_at')->whereNot(fn (Builder $w) => $this->inForce($w, $today)))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->applySearch($q, (string) $filters['search']));
    }

    /**
     * The id, day and time of every match, for the page to merge and sort
     * with its own deferrals before loading one page of either.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{source: string, id: int, date: string, at: string}>
     */
    public function keys(int $facilityId, array $filters): array
    {
        return $this->query($facilityId, $filters)
            ->get(['donation_screenings.id', 'donation_screenings.screened_at'])
            ->map(fn (DonationScreening $s): array => [
                'source' => 'screening',
                'id' => (int) $s->id,
                'date' => $s->screened_at ? OperationalDay::dateOf($s->screened_at) : '',
                'at' => (string) $s->screened_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, DonationScreening>
     */
    public function findMany(int $facilityId, array $ids): Collection
    {
        return DonationScreening::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->whereKey($ids)
            ->get();
    }

    /**
     * One screening deferral this centre recorded, or null.
     */
    public function find(int $facilityId, int $screeningId): ?DonationScreening
    {
        return DonationScreening::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->whereIn('outcome', [
                ScreeningOutcome::TemporarilyDeferred->value,
                ScreeningOutcome::PermanentlyDeferred->value,
                ScreeningOutcome::IndefiniteDeferral->value,
            ])
            ->find($screeningId);
    }

    /**
     * Whether a later screening of the same donor, anywhere, has superseded
     * a temporary deferral that set no Until date.
     */
    public function supersededLater(DonationScreening $screening): bool
    {
        return DonationScreening::query()
            ->join('donations', 'donations.id', '=', 'donation_screenings.donation_id')
            ->where('donations.donor_id', $screening->donation?->donor_id)
            ->where('donation_screenings.screened_at', '>', $screening->screened_at)
            ->exists();
    }

    private function inForce(Builder $query, string $today): void
    {
        // Each branch is true or false, never NULL: the Expired filter is
        // NOT of this, and a NULL Until date compared with today would drop
        // the row from both lists.
        $query->where('donation_screenings.outcome', '!=', ScreeningOutcome::TemporarilyDeferred->value)
            ->orWhere(fn (Builder $until) => $until
                ->whereNotNull('donation_screenings.deferred_until')
                ->whereDate('donation_screenings.deferred_until', '>=', $today))
            ->orWhere(fn (Builder $open) => $open
                ->whereNull('donation_screenings.deferred_until')
                ->whereNotExists(fn (QueryBuilder $later) => $later->select(DB::raw(1))
                    ->from('donation_screenings as later')
                    ->join('donations as later_donation', 'later_donation.id', '=', 'later.donation_id')
                    ->whereColumn('later_donation.donor_id', 'donations.donor_id')
                    ->whereColumn('later.screened_at', '>', 'donation_screenings.screened_at')));
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';
        $donationId = preg_match('/^DON-?(\d{1,10})$/i', $term, $m) === 1 ? (int) $m[1] : null;
        $donorId = preg_match('/^DONOR-(\d{1,10})$/i', $term, $d) === 1 ? (int) $d[1] : null;

        return $query->where(function (Builder $inner) use ($term, $like, $donationId, $donorId): void {
            $inner->whereRaw('LOWER(donation_screenings.deferral_reason) LIKE ?', [$like])
                ->orWhereRaw('LOWER(donation_screenings.deferral_code) = ?', [mb_strtolower($term)])
                ->orWhereExists(fn (QueryBuilder $donor) => $donor->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'donations.donor_id')
                    ->where(fn (QueryBuilder $name) => $name
                        ->whereRaw('LOWER(users.first_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(users.last_name) LIKE ?', [$like])
                        ->orWhereRaw("LOWER(users.first_name || ' ' || users.last_name) LIKE ?", [$like])))
                ->when($donorId !== null, fn (Builder $q) => $q->orWhere('donations.donor_id', $donorId))
                ->when($donationId !== null, fn (Builder $q) => $q->orWhere('donations.id', $donationId));
        });
    }
}
