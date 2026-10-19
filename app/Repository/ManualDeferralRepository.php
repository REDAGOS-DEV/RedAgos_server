<?php

namespace App\Repository;

use App\Models\DonorDeferral;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * One centre's hand-recorded deferrals as the Deferrals page lists them.
 *
 * Whether a donor is deferred is DonorDeferralRepository's question; this
 * only lists the records.
 */
class ManualDeferralRepository
{
    private const WITH = [
        'donor.donorProfile.bloodType',
        'recorder',
        'lifter',
    ];

    /**
     * Filters: search (donor name or DONOR- code, a DEF- code, or words of the
     * reason) and status (active, expired or lifted). Newest first.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $facilityId, array $filters): Builder
    {
        return DonorDeferral::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->when(($filters['status'] ?? null) === DonorDeferral::ACTIVE, fn (Builder $q) => $q->inForce())
            ->when(($filters['status'] ?? null) === DonorDeferral::EXPIRED, fn (Builder $q) => $q->expired())
            ->when(($filters['status'] ?? null) === DonorDeferral::LIFTED, fn (Builder $q) => $q->whereNotNull('lifted_at'))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->applySearch($q, (string) $filters['search']))
            ->orderByDesc('deferred_on')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DonorDeferral>
     */
    public function paginate(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($facilityId, $filters)->paginate($perPage);
    }

    /**
     * One deferral this centre recorded, or null.
     */
    public function find(int $facilityId, int $deferralId): ?DonorDeferral
    {
        return DonorDeferral::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->find($deferralId);
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';
        $deferralId = preg_match('/^DEF-?(\d{1,10})$/i', $term, $m) === 1 ? (int) $m[1] : null;
        $donorId = preg_match('/^DONOR-(\d{1,10})$/i', $term, $d) === 1 ? (int) $d[1] : null;

        return $query->where(function (Builder $inner) use ($like, $deferralId, $donorId): void {
            $inner->whereRaw('LOWER(reason) LIKE ?', [$like])
                ->orWhereHas('donor', fn (Builder $donor) => $donor
                    ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like]))
                ->when($donorId !== null, fn (Builder $q) => $q->orWhere('donor_id', $donorId))
                ->when($deferralId !== null, fn (Builder $q) => $q->orWhere('donor_deferrals.id', $deferralId));
        });
    }
}
