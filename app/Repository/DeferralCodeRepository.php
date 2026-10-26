<?php

namespace App\Repository;

use App\Models\DeferralCode;
use App\Models\DonationScreening;
use App\Models\DonorDeferral;
use Illuminate\Database\Eloquent\Collection;

/**
 * A blood centre's deferral codes, and where its records use them.
 */
class DeferralCodeRepository
{
    /**
     * The centre's codes in their natural order (R2 before R10), retired ones
     * only when asked for.
     *
     * @return Collection<int, DeferralCode>
     */
    public function forFacility(int $facilityId, bool $withRetired): Collection
    {
        return DeferralCode::query()
            ->with('updater')
            ->where('facility_id', $facilityId)
            ->when(! $withRetired, fn ($q) => $q->active())
            ->get()
            ->sortBy('code', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function find(int $facilityId, int $id): ?DeferralCode
    {
        return DeferralCode::query()
            ->where('facility_id', $facilityId)
            ->find($id);
    }

    /**
     * An active code, whatever the case it is sent in: r2-low finds R2-Low.
     */
    public function findActive(int $facilityId, string $code): ?DeferralCode
    {
        return DeferralCode::query()
            ->where('facility_id', $facilityId)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->active()
            ->first();
    }

    /**
     * Whether the centre already has the code in any case, retired ones included.
     */
    public function codeTaken(int $facilityId, string $code, ?int $exceptId = null): bool
    {
        return DeferralCode::query()
            ->where('facility_id', $facilityId)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function hasAny(int $facilityId): bool
    {
        return DeferralCode::query()->where('facility_id', $facilityId)->exists();
    }

    /**
     * Write the centre's first list. Ignores a code already there, so two
     * first visits at once cannot trip the unique index.
     *
     * @param  array<int, array{code: string, reason: string}>  $codes
     */
    public function seed(int $facilityId, array $codes): void
    {
        $now = now();

        DeferralCode::query()->insertOrIgnore(array_map(fn (array $row): array => [
            'facility_id' => $facilityId,
            'code' => $row['code'],
            'reason' => $row['reason'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $codes));
    }

    /**
     * How many of the centre's deferral records carry each code: screenings
     * (counter and paper) and deferrals recorded on the Deferrals page.
     *
     * @return array<string, int>
     */
    public function usage(int $facilityId): array
    {
        $counts = [];

        $screenings = DonationScreening::query()
            ->where('facility_id', $facilityId)
            ->whereNotNull('deferral_code')
            ->selectRaw('deferral_code as code, COUNT(*) as uses')
            ->groupBy('deferral_code')
            ->pluck('uses', 'code');

        $deferrals = DonorDeferral::query()
            ->where('facility_id', $facilityId)
            ->whereNotNull('reason_code')
            ->selectRaw('reason_code as code, COUNT(*) as uses')
            ->groupBy('reason_code')
            ->pluck('uses', 'code');

        foreach ([$screenings, $deferrals] as $source) {
            foreach ($source as $code => $uses) {
                $counts[$code] = ($counts[$code] ?? 0) + (int) $uses;
            }
        }

        return $counts;
    }

    public function inUse(int $facilityId, string $code): bool
    {
        return DonationScreening::query()->where('facility_id', $facilityId)->where('deferral_code', $code)->exists()
            || DonorDeferral::query()->where('facility_id', $facilityId)->where('reason_code', $code)->exists();
    }
}
