<?php

namespace App\Repository;

use App\Enums\BloodUnitStatus;
use App\Models\BloodUnit;
use App\Models\Donation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every read and write of blood stock, scoped to one facility.
 *
 * The isolation boundary lives here rather than in the controller: every method
 * except the sweep's takes a facility id and applies it, so a caller cannot
 * reach another centre's stock by forgetting a where clause.
 */
class InventoryRepository
{
    /**
     * List a facility's units, filtered and FEFO-ordered.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, BloodUnit>
     */
    public function paginateUnits(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($facilityId, $filters)
            ->with(['bloodType', 'component'])
            ->fefo()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Find one unit belonging to this facility.
     *
     * A scoped lookup rather than route model binding: unit ids are strings
     * read off a printed bag and are guessable, so implicit binding would
     * resolve another facility's unit and only then be rejected. Resolving it
     * scoped means the wrong facility gets a 404 that reveals nothing.
     */
    public function findUnitForFacility(string $unitId, int $facilityId): ?BloodUnit
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->whereKey($unitId)
            ->first();
    }

    /**
     * Re-read a unit under a row lock for a write that must not race.
     *
     * Must be called inside a transaction; a lock taken outside one is released
     * immediately and proves nothing.
     */
    public function lockUnit(string $unitId, int $facilityId): ?BloodUnit
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->whereKey($unitId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Re-read a donation under a row lock, scoped to the caller's facility.
     *
     * Intake always locks: the unit-id sequence is namespaced by donation, so
     * the donation row is what concurrent intake has to serialise on. There is
     * deliberately no unlocked variant — it would only be a trap for the next
     * caller.
     *
     * Must be called inside a transaction.
     */
    public function lockDonation(int $donationId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereKey($donationId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The unit ids already issued under a donation's generated-id prefix.
     *
     * Read under the donation lock, and returned as ids rather than a count:
     * counting is wrong the moment one unit was recorded with a staff-supplied
     * id, which does not carry the prefix at all.
     *
     * @return array<int, string>
     */
    public function existingUnitIds(int $donationId, string $prefix): array
    {
        return BloodUnit::query()
            ->where('donation_id', $donationId)
            ->where('id', 'like', $prefix.'%')
            ->pluck('id')
            ->all();
    }

    /**
     * Which of these ids already exist, regardless of facility.
     *
     * Used to turn a lost unique-violation race on a staff-supplied id back
     * into the 422 the validator would have returned. Not facility-scoped
     * because the primary key is global.
     *
     * @param  array<int, string>  $unitIds
     * @return array<int, string>
     */
    public function existingIdsAmong(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return BloodUnit::query()
            ->whereIn('id', $unitIds)
            ->pluck('id')
            ->all();
    }

    /**
     * Insert one unit.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createUnit(array $attributes): BloodUnit
    {
        $unit = new BloodUnit;

        $unit->fill($attributes);
        $unit->save();

        return $unit;
    }

    /**
     * Count a facility's units by status.
     *
     * A grouped aggregate, never a PHP loop over a full table read: a centre's
     * stock is the one table here that grows without bound.
     *
     * @return array<string, int>
     */
    public function summaryCounts(int $facilityId): array
    {
        $counts = BloodUnit::query()
            ->forFacility($facilityId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        // Projected from the enum so every status appears, including the ones
        // this facility currently has none of.
        $totals = [];

        foreach (BloodUnitStatus::values() as $status) {
            $totals[$status] = (int) ($counts[$status] ?? 0);
        }

        return $totals;
    }

    /**
     * Available units per blood type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function countsByBloodType(int $facilityId): array
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->where('blood_units.status', BloodUnitStatus::Available)
            ->join('blood_types', 'blood_types.id', '=', 'blood_units.blood_type_id')
            ->groupBy('blood_types.id', 'blood_types.code')
            ->orderBy('blood_types.id')
            ->selectRaw('blood_types.id as blood_type_id, blood_types.code as code, COUNT(*) as available')
            ->get()
            ->map(fn ($row): array => [
                'blood_type_id' => (int) $row->blood_type_id,
                'code' => $row->code,
                'available' => (int) $row->available,
            ])
            ->all();
    }

    /**
     * Available units per component.
     *
     * @return array<int, array<string, mixed>>
     */
    public function countsByComponent(int $facilityId): array
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->where('blood_units.status', BloodUnitStatus::Available)
            ->join('blood_components', 'blood_components.id', '=', 'blood_units.component_id')
            ->groupBy('blood_components.id', 'blood_components.name')
            ->orderBy('blood_components.name')
            ->selectRaw('blood_components.id as component_id, blood_components.name as name, COUNT(*) as available')
            ->get()
            ->map(fn ($row): array => [
                'component_id' => (int) $row->component_id,
                'name' => $row->name,
                'available' => (int) $row->available,
            ])
            ->all();
    }

    /**
     * How much stock is close to its date, and how much is already past it.
     *
     * `expired` counts units stored as expired, like every other number here.
     * It does not re-derive expiry from dates at read time: if it disagrees
     * with the shelf, the sweep is not running, and that is a deployment fault
     * to fix rather than to paper over on every request.
     *
     * @return array<string, int>
     */
    public function nearExpiryCounts(int $facilityId, string $operationalDate): array
    {
        $within = fn (int $days): int => BloodUnit::query()
            ->forFacility($facilityId)
            ->where('status', BloodUnitStatus::Available)
            ->whereBetween('expiry_date', [
                $operationalDate,
                CarbonImmutable::parse($operationalDate)->addDays($days)->toDateString(),
            ])
            ->count();

        return [
            'expired' => BloodUnit::query()
                ->forFacility($facilityId)
                ->where('status', BloodUnitStatus::Expired)
                ->count(),
            'within_3_days' => $within(3),
            'within_7_days' => $within(7),
        ];
    }

    /**
     * The storage locations this facility has actually recorded against units.
     *
     * @return array<int, string>
     */
    public function distinctStorageLocations(int $facilityId): array
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->whereNotNull('storage_location')
            ->distinct()
            ->orderBy('storage_location')
            ->pluck('storage_location')
            ->all();
    }

    /**
     * Units past their expiry date that are still marked available.
     *
     * The one method here that is deliberately NOT facility-scoped: the sweep
     * is a system actor and runs across every centre.
     *
     * Its result is a list of CANDIDATES, not a list of decisions. Nothing may
     * be written on the strength of it — a staff member can discard a bag or
     * correct a mistyped expiry between this select and the update. Pass the
     * ids through lockConfirmedDueUnits() first.
     *
     * @return Builder<BloodUnit>
     */
    public function dueUnits(string $operationalDate): Builder
    {
        return BloodUnit::query()
            ->where('status', BloodUnitStatus::Available)
            ->whereDate('expiry_date', '<', $operationalDate);
    }

    /**
     * Re-assert both due predicates under a row lock.
     *
     * Both predicates, not just the status: correcting a mistyped expiry to a
     * future date is the second way staff can legitimately take a unit out of
     * scope, and only the date predicate catches that one.
     *
     * Must be called inside a transaction — the lock is the entire point, and
     * outside one it is released immediately.
     *
     * @param  array<int, string>  $candidateIds
     * @return Collection<int, BloodUnit>
     */
    public function lockConfirmedDueUnits(array $candidateIds, string $operationalDate): Collection
    {
        if ($candidateIds === []) {
            return new Collection;
        }

        return BloodUnit::query()
            ->whereIn('id', $candidateIds)
            ->where('status', BloodUnitStatus::Available)
            ->whereDate('expiry_date', '<', $operationalDate)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Flip confirmed units to expired, returning how many rows moved.
     *
     * The same predicates ride in the WHERE, so the statement is still correct
     * if someone later refactors the lock away, and the caller compares the
     * affected count against the confirmed set rather than trusting either
     * alone.
     *
     * @param  array<int, string>  $confirmedIds
     */
    public function markExpired(array $confirmedIds, string $operationalDate, CarbonImmutable $sweptAt): int
    {
        if ($confirmedIds === []) {
            return 0;
        }

        return BloodUnit::query()
            ->whereIn('id', $confirmedIds)
            ->where('status', BloodUnitStatus::Available)
            ->whereDate('expiry_date', '<', $operationalDate)
            ->update([
                'status' => BloodUnitStatus::Expired,
                'expired_at' => $sweptAt,
                'updated_at' => $sweptAt,
            ]);
    }

    /**
     * Apply the listing filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<BloodUnit>
     */
    private function filtered(int $facilityId, array $filters): Builder
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['blood_type_id']),
                fn (Builder $query): Builder => $query->where('blood_type_id', $filters['blood_type_id'])
            )
            ->when(
                isset($filters['component_id']),
                fn (Builder $query): Builder => $query->where('component_id', $filters['component_id'])
            )
            ->when(
                isset($filters['storage_location']),
                fn (Builder $query): Builder => $query->where('storage_location', $filters['storage_location'])
            )
            ->when(
                isset($filters['donation_id']),
                fn (Builder $query): Builder => $query->where('donation_id', $filters['donation_id'])
            )
            ->when(
                isset($filters['expiring_within_days']),
                fn (Builder $query): Builder => $query
                    ->where('status', BloodUnitStatus::Available)
                    ->whereBetween('expiry_date', [
                        $filters['operational_date'],
                        CarbonImmutable::parse($filters['operational_date'])
                            ->addDays((int) $filters['expiring_within_days'])
                            ->toDateString(),
                    ])
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->where('id', 'like', '%'.$filters['search'].'%')
            );
    }
}
