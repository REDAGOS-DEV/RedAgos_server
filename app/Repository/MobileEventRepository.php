<?php

namespace App\Repository;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\MobileEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MobileEventRepository
{
    /**
     * Every drive a facility has run or scheduled, newest first.
     *
     * Unlike the donor-facing catalogue this deliberately keeps past drives:
     * the blood centre manages history here, and a drive that has happened is
     * still the record of what happened.
     *
     * @return Collection<int, MobileEvent>
     */
    public function forFacility(int $facilityId): Collection
    {
        return MobileEvent::query()
            ->with('facility')
            ->withCount(['appointments as registered' => fn ($query) => $query->active()])
            ->where('facility_id', $facilityId)
            ->orderByDesc('event_date')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): MobileEvent
    {
        $event = MobileEvent::query()->create($attributes);

        // The caller formats the fresh row alongside listed ones, which carry a
        // registered count from withCount(). A new drive has none, but the
        // attribute has to exist or status() would fall back to a query.
        $event->setAttribute('registered', 0);

        return $event->load('facility');
    }

    /**
     * Units actually drawn at this facility in the given month.
     *
     * Counts only donations that reached the bag: `registered` and `screening`
     * are donors still in the chair, and `rejected` means nothing was taken.
     */
    public function unitsCollectedInMonth(int $facilityId, Carbon $month): int
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('donation_date', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ])
            ->whereIn('status', [
                DonationStatus::Collected->value,
                DonationStatus::Tested->value,
                DonationStatus::Completed->value,
            ])
            ->count();
    }
}
