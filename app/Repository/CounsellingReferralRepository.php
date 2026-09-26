<?php

namespace App\Repository;

use App\Enums\ReferralStatus;
use App\Models\CounsellingReferral;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * One facility's counselling referrals.
 *
 * Scoped to the facility that recorded the reactive result: the follow-up is
 * that centre's duty, and the list names which infection each donor carries.
 */
class CounsellingReferralRepository
{
    /**
     * Page this facility's referrals, newest first.
     *
     * Open referrals by default: the list is a to-do list, and a closed one is
     * looked up on purpose.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CounsellingReferral>
     */
    public function paginateForFacility(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        $status = $filters['status'] ?? 'open';

        return CounsellingReferral::query()
            ->with([
                'donorProfile.donor',
                'donation.collection',
                'donation.serology.recorder',
                'updater',
            ])
            ->where('facility_id', $facilityId)
            ->when(
                $status === 'open',
                fn (Builder $q): Builder => $q->where('status', '!=', ReferralStatus::Closed->value),
                fn (Builder $q): Builder => $status === 'all'
                    ? $q
                    : $q->where('status', $status)
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Count the referrals nobody has acted on yet, for the Testing page's badge.
     */
    public function pendingCount(int $facilityId): int
    {
        return CounsellingReferral::query()
            ->where('facility_id', $facilityId)
            ->where('status', ReferralStatus::Pending->value)
            ->count();
    }

    /**
     * Re-read one of this facility's referrals under a row lock.
     */
    public function lockReferral(int $referralId, int $facilityId): ?CounsellingReferral
    {
        return CounsellingReferral::query()
            ->where('id', $referralId)
            ->where('facility_id', $facilityId)
            ->lockForUpdate()
            ->first();
    }

    public function findReferral(int $referralId, int $facilityId): ?CounsellingReferral
    {
        return CounsellingReferral::query()
            ->with([
                'donorProfile.donor',
                'donation.collection',
                'donation.serology.recorder',
                'updater',
            ])
            ->where('id', $referralId)
            ->where('facility_id', $facilityId)
            ->first();
    }
}
