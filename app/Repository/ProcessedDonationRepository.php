<?php

namespace App\Repository;

use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Models\BloodComponent;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * One centre's processed donations, as Processing's Processed list shows them:
 * every donation with a recorded component breakdown, whatever became of it.
 */
class ProcessedDonationRepository
{
    private const WITH = [
        'donorProfile.donor',
        'donorProfile.bloodType',
        'collection',
        'components.component',
        'components.declarer',
        'clearances',
        'labeller',
    ];

    /**
     * The donation statuses behind each hand-over filter.
     *
     * @var array<string, array<int, string>>
     */
    private const STATUSES = [
        'awaiting_handover' => [DonationStatus::Collected->value, DonationStatus::Tested->value],
        'handed_over' => [DonationStatus::Completed->value],
        'rejected' => [DonationStatus::Rejected->value],
    ];

    /**
     * Filters: search (a bag's barcode, a bag number, a DON- code, and — only
     * when $searchIdentity — the donor's name or DONOR- code), status
     * (awaiting_handover, handed_over, rejected, or correction_pending: a
     * breakdown correction waits for a decision) and sort (newest or oldest,
     * by when the breakdown was recorded). Newest first by default.
     *
     * Processing works blind, so a name search is refused to it the same way
     * the serology list refuses it to TTI Testing.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $facilityId, array $filters, bool $searchIdentity): Builder
    {
        // When the breakdown was recorded: a correction replaces its rows, so
        // the latest row's time is the breakdown in force.
        $processedAt = DonationComponent::query()
            ->selectRaw('MAX(created_at)')
            ->whereColumn('donation_components.donation_id', 'donations.id');

        $query = Donation::query()
            ->where('donations.facility_id', $facilityId)
            ->whereHas('components')
            ->with(self::WITH)
            ->withCount('bloodUnits')
            ->when(isset(self::STATUSES[$filters['status'] ?? '']), fn (Builder $q) => $q->whereIn('donations.status', self::STATUSES[$filters['status']]))
            ->when(($filters['status'] ?? null) === 'correction_pending', fn (Builder $q) => $q->whereIn(
                'donations.id',
                CorrectionRequest::query()
                    ->select('donation_id')
                    ->where('subject', CorrectionSubject::Components->value)
                    ->where('status', 'pending')
            ))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->applySearch($q, (string) $filters['search'], $searchIdentity));

        return ($filters['sort'] ?? null) === 'oldest'
            ? $query->orderBy($processedAt)->orderBy('donations.id')
            : $query->orderByDesc($processedAt)->orderByDesc('donations.id');
    }

    /**
     * Of these donations, the breakdown corrections waiting for a decision,
     * keyed by donation id. One at most per donation: the correction route
     * refuses a second while one is pending.
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
            ->where('subject', CorrectionSubject::Components->value)
            ->where('status', 'pending')
            ->get()
            ->keyBy('donation_id');
    }

    /**
     * The names of the components a pending correction proposes, by id.
     *
     * @param  array<int, int>  $componentIds
     * @return array<int, string>
     */
    public function componentNames(array $componentIds): array
    {
        if ($componentIds === []) {
            return [];
        }

        return BloodComponent::query()->whereIn('id', $componentIds)->pluck('name', 'id')->all();
    }

    private function applySearch(Builder $query, string $term, bool $searchIdentity): Builder
    {
        $term = trim($term);
        $compact = mb_strtoupper(preg_replace('/\s+/', '', $term) ?? $term);
        $barcode = '%'.$compact.'%';
        $like = '%'.mb_strtolower($term).'%';
        $donationId = preg_match('/^(?:DON-?|#)(\d{1,10})$/i', $term, $m) === 1 ? (int) $m[1] : null;
        $donorId = preg_match('/^DONOR-(\d{1,10})$/i', $term, $d) === 1 ? (int) $d[1] : null;

        return $query->where(function (Builder $inner) use ($barcode, $compact, $like, $donationId, $donorId, $searchIdentity): void {
            // A bag number is the sticker plus its component (1234567-PRBC),
            // so a scanned or typed one finds the donation it came from.
            $inner->whereHas('collection', fn (Builder $c) => $c
                ->whereRaw('UPPER(donation_barcode) LIKE ?', [$barcode])
                ->orWhereRaw("? LIKE UPPER(donation_barcode) || '-%'", [$compact]))
                ->when($donationId !== null, fn (Builder $q) => $q->orWhere('donations.id', $donationId))
                ->when($searchIdentity, fn (Builder $q) => $q->orWhereHas('donorProfile.donor', fn (Builder $donor) => $donor
                    ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                    ->when($donorId !== null, fn (Builder $q) => $q->orWhere('users.id', $donorId))));
        });
    }
}
