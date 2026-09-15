<?php

namespace App\Repository;

use App\Enums\BloodUnitStatus;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Models\BloodUnit;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cross-facility reads of what stock exists, for requesters choosing where to ask.
 *
 * Deliberately separate from InventoryRepository, whose stated contract is that
 * every method is scoped to one facility. This one is the opposite by design —
 * it reads across the network — and mixing the two would make that repository's
 * isolation guarantee untrue for some of its methods.
 *
 * Nothing here writes, locks, or reserves. A search result is a statement about
 * the past tense, and the allocation service re-checks everything under a row
 * lock before any unit is actually held.
 */
class AvailabilityRepository
{
    /**
     * Count available units per eligible facility for one blood type and component.
     *
     * Grouped in SQL rather than by loading units and counting in PHP: a
     * network-wide unit list is unbounded, and only the totals are wanted.
     *
     * @return Collection<int, object>
     */
    public function countsByFacility(
        int $bloodTypeId,
        int $componentId,
        string $operationalDate,
        ?int $excludeFacilityId = null
    ): Collection {
        return BloodUnit::query()
            ->join('facilities', 'facilities.id', '=', 'blood_units.facility_id')
            ->join('facility_types', 'facility_types.id', '=', 'facilities.facility_type_id')
            ->where('blood_units.blood_type_id', $bloodTypeId)
            ->where('blood_units.component_id', $componentId)
            ->tap(fn (Builder $query) => $this->onlyIssuableStock($query, $operationalDate))
            ->tap(fn (Builder $query) => $this->onlyEligibleTargets($query, $excludeFacilityId))
            ->groupBy('facilities.id', 'facilities.name', 'facilities.address')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('facilities.name')
            ->selectRaw(
                'facilities.id as facility_id, facilities.name as facility_name, '
                .'facilities.address as address, COUNT(*) as available, '
                .'MIN(blood_units.expiry_date) as earliest_expiry'
            )
            ->get();
    }

    /**
     * List the facilities a request may be addressed to.
     *
     * Returned even when they hold no matching stock. A requester still needs
     * to see them: stock arrives, and a facility with nothing today is a
     * legitimate place to send a routine request for tomorrow.
     *
     * @return Collection<int, Facility>
     */
    public function eligibleTargets(?int $excludeFacilityId = null): Collection
    {
        return Facility::query()
            ->join('facility_types', 'facility_types.id', '=', 'facilities.facility_type_id')
            ->where('facility_types.name', FacilityTypeName::BloodCenter->value)
            ->where('facilities.status', FacilityStatus::Approved->value)
            ->when(
                $excludeFacilityId !== null,
                fn (Builder $query): Builder => $query->where('facilities.id', '!=', $excludeFacilityId)
            )
            ->orderBy('facilities.name')
            ->select('facilities.*')
            ->get();
    }

    /**
     * Count available units at one facility for one blood type and component.
     *
     * The single-facility form of countsByFacility, used when a target is
     * already chosen and only its own depth matters.
     */
    public function availableAt(
        int $facilityId,
        int $bloodTypeId,
        int $componentId,
        string $operationalDate
    ): int {
        return BloodUnit::query()
            ->where('blood_units.facility_id', $facilityId)
            ->where('blood_units.blood_type_id', $bloodTypeId)
            ->where('blood_units.component_id', $componentId)
            ->tap(fn (Builder $query) => $this->onlyIssuableStock($query, $operationalDate))
            ->count();
    }

    /**
     * Determine whether a facility may be sent a blood request.
     *
     * The one place the targeting rule is written down. Widening it later — to
     * let hospital blood banks fulfil each other's requests, say — is a change
     * here and nowhere else.
     */
    public function isEligibleTarget(Facility $facility): bool
    {
        $facility->loadMissing('facilityType');

        return $facility->facilityType?->name === FacilityTypeName::BloodCenter->value
            && $facility->status === FacilityStatus::Approved;
    }

    /**
     * Restrict a unit query to stock that could actually be issued today.
     *
     * Status and expiry are both applied. A unit whose date has passed but
     * which the nightly sweep has not reached yet still says `available`, and
     * showing it to a requester would promise blood that cannot legally leave
     * the building.
     */
    private function onlyIssuableStock(Builder $query, string $operationalDate): Builder
    {
        return $query
            ->where('blood_units.status', BloodUnitStatus::Available->value)
            ->whereDate('blood_units.expiry_date', '>=', $operationalDate);
    }

    /**
     * Restrict a joined query to facilities that may receive a request.
     *
     * Mirrors isEligibleTarget() in SQL. Both exist because one answers for a
     * loaded model and the other has to filter a grouped aggregate; they must
     * agree, so they are kept adjacent.
     */
    private function onlyEligibleTargets(Builder $query, ?int $excludeFacilityId): Builder
    {
        return $query
            ->where('facility_types.name', FacilityTypeName::BloodCenter->value)
            ->where('facilities.status', FacilityStatus::Approved->value)
            ->whereNull('facilities.deleted_at')
            ->when(
                $excludeFacilityId !== null,
                fn (Builder $inner): Builder => $inner->where('facilities.id', '!=', $excludeFacilityId)
            );
    }
}
