<?php

namespace App\Repository;

use App\Models\DonorDeferral;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
     * Filters: search (donor name or DONOR- code, a DEF- code, a deferral
     * code exactly, or words of the reason) and status (active, expired or
     * lifted). Newest first.
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
     * The id, day and time of every match, for the page to merge with the
     * centre's screening deferrals (ScreeningDeferralRepository::keys()).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{source: string, id: int, date: string, at: string}>
     */
    public function keys(int $facilityId, array $filters): array
    {
        return $this->query($facilityId, $filters)
            ->setEagerLoads([])
            ->get(['donor_deferrals.id', 'donor_deferrals.deferred_on', 'donor_deferrals.created_at'])
            ->map(fn (DonorDeferral $d): array => [
                'source' => 'manual',
                'id' => (int) $d->id,
                'date' => (string) $d->deferred_on?->toDateString(),
                'at' => (string) $d->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, DonorDeferral>
     */
    public function findMany(int $facilityId, array $ids): Collection
    {
        return DonorDeferral::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->whereKey($ids)
            ->get();
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

        return $query->where(function (Builder $inner) use ($term, $like, $deferralId, $donorId): void {
            $inner->whereRaw('LOWER(reason) LIKE ?', [$like])
                ->orWhereRaw('LOWER(reason_code) = ?', [mb_strtolower($term)])
                ->orWhereHas('donor', fn (Builder $donor) => $donor
                    ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like]))
                ->when($donorId !== null, fn (Builder $q) => $q->orWhere('donor_id', $donorId))
                ->when($deferralId !== null, fn (Builder $q) => $q->orWhere('donor_deferrals.id', $deferralId));
        });
    }
}
