<?php

namespace App\Repository;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Support\AccountIdentity;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * One centre's donations as the Donations list shows them: every visit opened
 * at its counter, from registration to completion or closing.
 */
class DonationRecordRepository
{
    private const WITH = [
        'donorProfile.donor',
        'donorProfile.bloodType',
        'screening.recorder',
        'collection.collector',
        'appointment',
        'appointment.mobileEvent',
        'paperSheet',
        'counsellingReferral',
    ];

    /**
     * Filters: search (donor name, phone or ID, a DON- code, or a bag's
     * barcode), status (a DonationStatus value, or "open" for a visit not yet
     * drawn), blood_type_id, from and to (operational dates, inclusive), and
     * paper_sheet_id (the rows typed from one drive's paper sheet). Newest
     * first.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $facilityId, array $filters): Builder
    {
        return Donation::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->when(($filters['status'] ?? null) === 'open', fn (Builder $q) => $q->whereIn('status', [
                DonationStatus::Registered->value,
                DonationStatus::Screening->value,
            ]))
            ->when(isset($filters['status']) && $filters['status'] !== 'open', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['paper_sheet_id']), fn (Builder $q) => $q->where('paper_sheet_id', $filters['paper_sheet_id']))
            ->when(isset($filters['blood_type_id']), fn (Builder $q) => $q->whereHas(
                'donorProfile',
                fn (Builder $p) => $p->where('blood_type_id', $filters['blood_type_id'])
            ))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('donation_date', '>=', OperationalDay::boundsFor($filters['from'])[0]))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('donation_date', '<=', OperationalDay::boundsFor($filters['to'])[1]))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->applySearch($q, (string) $filters['search']))
            ->orderByDesc('donation_date')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Donation>
     */
    public function paginate(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($facilityId, $filters)->paginate($perPage);
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';
        $donationId = preg_match('/^DON-?(\d{1,10})$/i', $term, $m) === 1 ? (int) $m[1] : null;
        $donorId = preg_match('/^DONOR-(\d{1,10})$/i', $term, $d) === 1 ? (int) $d[1] : null;
        $phone = preg_replace('/[\s-]+/', '', $term) ?? $term;
        $localPhone = preg_match('/^0(9\d*)$/', $phone, $p) === 1 ? '%+63'.$p[1].'%' : null;
        $barcode = AccountIdentity::normalizeValidIdNumber($term);

        return $query->where(function (Builder $inner) use ($like, $donationId, $donorId, $phone, $localPhone, $term, $barcode): void {
            $inner->whereHas('donorProfile.donor', fn (Builder $donor) => $donor
                ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                ->orWhere('phone', 'like', '%'.$phone.'%')
                ->when($localPhone !== null, fn (Builder $q) => $q->orWhere('phone', 'like', $localPhone))
                ->when($donorId !== null, fn (Builder $q) => $q->orWhere('users.id', $donorId)))
                ->orWhereHas('collection', fn (Builder $c) => $c
                    ->where('donation_barcode', $term)
                    ->when($barcode !== null && $barcode !== $term, fn (Builder $q) => $q->orWhere('donation_barcode', $barcode)))
                ->when($donationId !== null, fn (Builder $q) => $q->orWhere('donations.id', $donationId));
        });
    }
}
