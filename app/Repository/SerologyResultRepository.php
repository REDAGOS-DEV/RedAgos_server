<?php

namespace App\Repository;

use App\Enums\BloodUnitStatus;
use App\Enums\CorrectionSubject;
use App\Enums\MarkerResult;
use App\Enums\SerologyMarker;
use App\Models\BloodUnit;
use App\Models\CorrectionRequest;
use App\Models\DonationSerology;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * One centre's recorded serology panels, as TTI Testing's results list shows them.
 */
class SerologyResultRepository
{
    private const WITH = [
        'recorder',
        'verifier',
        'donation.donorProfile.donor',
        'donation.donorProfile.bloodType',
        'donation.collection',
    ];

    /**
     * Filters: search (a bag's barcode, a DON- code, and — only when
     * $searchIdentity — the donor's name or DONOR- code), outcome (reactive,
     * non_reactive, awaiting_verification: reactive and not yet verified, or
     * correction_pending: a correction waits for the supervisor) and sort (newest, oldest or reactive_first). Newest first
     * by default.
     *
     * A viewer blind to identity cannot search by name: a name that narrows
     * the list to one reactive panel would tell them who it belongs to.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $facilityId, array $filters, bool $searchIdentity): Builder
    {
        $query = DonationSerology::query()
            ->select('donation_serology.*')
            ->join('donations', 'donations.id', '=', 'donation_serology.donation_id')
            ->where('donations.facility_id', $facilityId)
            ->with(self::WITH)
            ->when(($filters['outcome'] ?? null) === MarkerResult::Reactive->value, fn (Builder $q) => $q->where(fn (Builder $any) => $this->anyReactive($any)))
            ->when(($filters['outcome'] ?? null) === MarkerResult::NonReactive->value, fn (Builder $q) => $q->whereNot(fn (Builder $any) => $this->anyReactive($any)))
            ->when(($filters['outcome'] ?? null) === 'awaiting_verification', fn (Builder $q) => $q
                ->where(fn (Builder $any) => $this->anyReactive($any))
                ->whereNull('donation_serology.verified_at'))
            ->when(($filters['outcome'] ?? null) === 'correction_pending', fn (Builder $q) => $q->whereIn(
                'donation_serology.donation_id',
                CorrectionRequest::query()
                    ->select('donation_id')
                    ->where('subject', CorrectionSubject::Serology->value)
                    ->where('status', 'pending')
            ))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->applySearch($q, (string) $filters['search'], $searchIdentity));

        if (($filters['sort'] ?? null) === 'reactive_first') {
            $markers = implode(' OR ', array_map(
                fn (SerologyMarker $marker): string => "donation_serology.{$marker->value} = '".MarkerResult::Reactive->value."'",
                SerologyMarker::cases()
            ));

            $query->orderByRaw("CASE WHEN {$markers} THEN 0 ELSE 1 END");
        }

        return ($filters['sort'] ?? null) === 'oldest'
            ? $query->orderBy('donation_serology.recorded_at')->orderBy('donation_serology.id')
            : $query->orderByDesc('donation_serology.recorded_at')->orderByDesc('donation_serology.id');
    }

    /**
     * Of these donations, the serology corrections waiting for a decision,
     * keyed by donation id. One at most per donation: the route refuses a
     * second while one is pending.
     *
     * @param  array<int, int>  $donationIds
     * @return Collection<int, CorrectionRequest>
     */
    public function pendingCorrections(array $donationIds): Collection
    {
        if ($donationIds === []) {
            return new Collection;
        }

        return CorrectionRequest::query()
            ->with('requester')
            ->whereIn('donation_id', $donationIds)
            ->where('subject', CorrectionSubject::Serology->value)
            ->where('status', 'pending')
            ->get()
            ->keyBy('donation_id');
    }

    /**
     * Of these donations, the ones with a bag that has left quarantine.
     *
     * @param  array<int, int>  $donationIds
     * @return array<int, int>
     */
    public function withReleasedUnits(array $donationIds): array
    {
        if ($donationIds === []) {
            return [];
        }

        return BloodUnit::query()
            ->whereIn('donation_id', $donationIds)
            ->whereIn('status', BloodUnitStatus::leftQuarantineValues())
            ->distinct()
            ->pluck('donation_id')
            ->all();
    }

    private function anyReactive(Builder $query): void
    {
        foreach (SerologyMarker::cases() as $marker) {
            $query->orWhere("donation_serology.{$marker->value}", MarkerResult::Reactive->value);
        }
    }

    private function applySearch(Builder $query, string $term, bool $searchIdentity): Builder
    {
        $term = trim($term);
        $barcode = '%'.mb_strtoupper(preg_replace('/\s+/', '', $term) ?? $term).'%';
        $like = '%'.mb_strtolower($term).'%';
        $donationId = preg_match('/^(?:DON-?|#)(\d{1,10})$/i', $term, $m) === 1 ? (int) $m[1] : null;
        $donorId = preg_match('/^DONOR-(\d{1,10})$/i', $term, $d) === 1 ? (int) $d[1] : null;

        return $query->where(function (Builder $inner) use ($barcode, $like, $donationId, $donorId, $searchIdentity): void {
            $inner->whereHas('donation.collection', fn (Builder $c) => $c->whereRaw('UPPER(donation_barcode) LIKE ?', [$barcode]))
                ->when($donationId !== null, fn (Builder $q) => $q->orWhere('donation_serology.donation_id', $donationId))
                ->when($searchIdentity, fn (Builder $q) => $q->orWhereHas('donation.donorProfile.donor', fn (Builder $donor) => $donor
                    ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                    ->when($donorId !== null, fn (Builder $q) => $q->orWhere('users.id', $donorId))));
        });
    }
}
