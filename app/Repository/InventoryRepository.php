<?php

namespace App\Repository;

use App\Enums\AllocationStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Models\AuditLog;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\RequestAllocation;
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
            // The donation and its clearances, for the quarantine state of a
            // held unit, and its date, for when the bag was collected.
            // Constrained so a page of available stock does not drag every
            // donation's columns along.
            ->with(['bloodType', 'component', 'donation:id,status,donation_date', 'donation.clearances'])
            // FEFO unless the caller asks for the newest bags first: a listing
            // of every status in FEFO order opens on long-gone units.
            ->when(
                ($filters['sort'] ?? 'fefo') === 'recent',
                fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'),
                fn (Builder $query): Builder => $query->fefo()
            )
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Units this facility issued between two instants.
     *
     * Counted from the allocation's released_at, the moment a bag left the
     * building, and scoped through the unit, so only this centre's own bags
     * count.
     */
    public function issuedCountBetween(int $facilityId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return RequestAllocation::query()
            ->join('blood_units', 'blood_units.id', '=', 'request_allocations.unit_id')
            ->where('blood_units.facility_id', $facilityId)
            ->where('request_allocations.status', AllocationStatus::Released->value)
            ->whereBetween('request_allocations.released_at', [$from, $to])
            ->count();
    }

    /**
     * Units this facility released from quarantine to stock since an instant.
     */
    public function releasedToStockSince(int $facilityId, CarbonImmutable $since): int
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->where('released_at', '>=', $since)
            ->count();
    }

    /**
     * What happened to this facility's units, newest first.
     *
     * Read from the audit trail, scoped through the unit each row is about
     * rather than through the row's context: a facility id in the context is
     * written by some actions and not others, and the unit is the one thing
     * every row has. Only the actions this centre itself takes are listed, so
     * a hospital confirming receipt of a bag never shows up here.
     *
     * @param  array<int, string>  $actions
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginateActivity(int $facilityId, array $actions, ?string $unitId, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->join('blood_units', 'blood_units.id', '=', 'audit_logs.auditable_id')
            ->join('blood_types', 'blood_types.id', '=', 'blood_units.blood_type_id')
            ->join('blood_components', 'blood_components.id', '=', 'blood_units.component_id')
            ->where('audit_logs.auditable_type', BloodUnit::class)
            ->where('blood_units.facility_id', $facilityId)
            ->whereIn('audit_logs.action', $actions)
            ->when($unitId !== null, fn (Builder $query): Builder => $query->where('blood_units.id', $unitId))
            ->with('actor:id,first_name,last_name')
            ->select([
                'audit_logs.id',
                'audit_logs.actor_id',
                'audit_logs.action',
                'audit_logs.auditable_id',
                'audit_logs.created_at',
                'blood_types.code as blood_type_code',
                'blood_components.name as component_name',
            ])
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
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
    /**
     * The ids, among these, of units whose donation lacks either clearance token.
     *
     * @param  array<int, string>  $unitIds
     * @return array<int, string>
     */
    public function unitsLackingClearance(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return BloodUnit::query()
            ->whereIn('id', $unitIds)
            ->where(function (Builder $query): void {
                foreach (ClearanceKind::cases() as $kind) {
                    $query->orWhereDoesntHave(
                        'donation.clearances',
                        fn (Builder $clearance): Builder => $clearance->where('kind', $kind->value)
                    );
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Lock a donation's quarantined units, for release.
     *
     * @return Collection<int, BloodUnit>
     */
    public function lockQuarantinedUnits(int $donationId, int $facilityId): Collection
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->where('donation_id', $donationId)
            ->where('status', BloodUnitStatus::Quarantined)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

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
    /**
     * Donations this facility has cleared for issue that still owe units.
     *
     * "Still owes units" is decided by comparing totals rather than by walking
     * each component, which is sound only because recordUnits() refuses to book
     * more of a component than the laboratory declared: per-component recorded
     * can never exceed declared, so equal totals mean every component is
     * complete. If that guard is ever relaxed, this has to become a
     * per-component comparison.
     *
     * A discarded unit still counts as recorded — it was booked in once, and
     * discarding it does not entitle anyone to book another bag in its place.
     *
     * @return LengthAwarePaginator<int, Donation>
     */
    public function paginateIntakeQueue(int $facilityId, int $perPage, ?string $barcode = null): LengthAwarePaginator
    {
        return Donation::query()
            ->with(['donorProfile.donor', 'donorProfile.bloodType', 'testResult.bloodType', 'components.component', 'collection'])
            ->where('facility_id', $facilityId)
            ->where('status', DonationStatus::Completed->value)
            // A scanned sticker: the donation whose bags are on the counter.
            ->when(
                $barcode !== null,
                fn (Builder $q): Builder => $q->whereHas(
                    'collection',
                    fn (Builder $c): Builder => $c->where('donation_barcode', $barcode)
                )
            )
            ->whereRaw(
                '(select coalesce(sum(quantity), 0) from donation_components where donation_components.donation_id = donations.id)'
                .' > (select count(*) from blood_units where blood_units.donation_id = donations.id)'
            )
            // Oldest first: a bag that has been sitting since yesterday is the
            // one closest to its expiry, so it is the one to shelve next.
            ->orderBy('donation_date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * One of this facility's donations, without locking.
     */
    public function findDonation(int $donationId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereKey($donationId)
            ->first();
    }

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
     * Stock on hand per blood type, split the way the Issuance dashboard draws it.
     *
     * `expiring_soon` is the part of `available` dated within seven days, the
     * same window as nearExpiryCounts(). `reserved` is held for a request and
     * `quarantined` is booked in but not yet cleared. A type appears once it
     * has any of the three.
     *
     * @return array<int, array{blood_type_id: int, code: string, available: int, expiring_soon: int, reserved: int, quarantined: int}>
     */
    public function stockByBloodType(int $facilityId, string $operationalDate): array
    {
        $soonUntil = CarbonImmutable::parse($operationalDate)->addDays(7)->toDateString();

        return BloodUnit::query()
            ->forFacility($facilityId)
            ->whereIn('blood_units.status', [
                BloodUnitStatus::Available->value,
                BloodUnitStatus::Reserved->value,
                BloodUnitStatus::Quarantined->value,
            ])
            ->join('blood_types', 'blood_types.id', '=', 'blood_units.blood_type_id')
            ->groupBy('blood_types.id', 'blood_types.code')
            ->orderBy('blood_types.id')
            ->selectRaw(
                'blood_types.id as blood_type_id, blood_types.code as code,'
                .' SUM(CASE WHEN blood_units.status = ? THEN 1 ELSE 0 END) as available,'
                .' SUM(CASE WHEN blood_units.status = ? AND blood_units.expiry_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_soon,'
                .' SUM(CASE WHEN blood_units.status = ? THEN 1 ELSE 0 END) as reserved,'
                .' SUM(CASE WHEN blood_units.status = ? THEN 1 ELSE 0 END) as quarantined',
                [
                    BloodUnitStatus::Available->value,
                    BloodUnitStatus::Available->value,
                    $operationalDate,
                    $soonUntil,
                    BloodUnitStatus::Reserved->value,
                    BloodUnitStatus::Quarantined->value,
                ]
            )
            ->get()
            ->map(fn ($row): array => [
                'blood_type_id' => (int) $row->blood_type_id,
                'code' => $row->code,
                'available' => (int) $row->available,
                'expiring_soon' => (int) $row->expiring_soon,
                'reserved' => (int) $row->reserved,
                'quarantined' => (int) $row->quarantined,
            ])
            ->all();
    }

    /**
     * The facility's stock levels as they stood at one instant, rebuilt from
     * the timestamps each unit and each hold carries.
     *
     * A unit counts from the moment it was booked in until it was issued,
     * discarded or expired. Until its release it is quarantined. One with no
     * release stamp that has since left quarantine went straight into stock
     * (seeded or older rows), so it counts as released on arrival. Once
     * released it is reserved while a hold on it was open, and available
     * otherwise. Bags a hospital recorded from a direct distribution were
     * never this centre's stock and are left out.
     *
     * `$at` is an instant in the application's timezone, as boundsFor() gives
     * it; `$operationalDate` is that instant's date, for the expiry window.
     *
     * @return array{available: int, expiring_soon: int, reserved: int, quarantined: int}
     */
    public function levelsAt(int $facilityId, CarbonImmutable $at, string $operationalDate): array
    {
        $soonUntil = CarbonImmutable::parse($operationalDate)->addDays(7)->toDateString();
        $leftQuarantine = BloodUnitStatus::leftQuarantineValues();
        $statusList = implode(', ', array_fill(0, count($leftQuarantine), '?'));

        // Written so neither ever evaluates to NULL: NOT NULL is NULL, and a
        // CASE on it would quietly count the unit nowhere.
        $released = '((blood_units.released_at IS NOT NULL AND blood_units.released_at <= ?)'
            ." OR (blood_units.released_at IS NULL AND blood_units.status IN ({$statusList})))";
        $held = 'EXISTS (SELECT 1 FROM request_allocations AS ra_hold'
            .' WHERE ra_hold.unit_id = blood_units.id'
            .' AND ra_hold.allocated_at <= ?'
            .' AND (ra_hold.released_at IS NULL OR ra_hold.released_at > ?)'
            .' AND (ra_hold.cancelled_at IS NULL OR ra_hold.cancelled_at > ?))';

        $releasedBindings = [$at, ...$leftQuarantine];
        $heldBindings = [$at, $at, $at];

        $row = BloodUnit::query()
            ->forFacility($facilityId)
            ->whereNull('blood_units.direct_distribution_id')
            ->where('blood_units.created_at', '<=', $at)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('blood_units.discarded_at')
                ->orWhere('blood_units.discarded_at', '>', $at))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('blood_units.expired_at')
                ->orWhere('blood_units.expired_at', '>', $at))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('request_allocations as ra_issue')
                ->whereColumn('ra_issue.unit_id', 'blood_units.id')
                ->where('ra_issue.status', AllocationStatus::Released->value)
                ->where('ra_issue.released_at', '<=', $at))
            ->selectRaw(
                "SUM(CASE WHEN NOT {$released} THEN 1 ELSE 0 END) as quarantined,"
                ." SUM(CASE WHEN {$released} AND {$held} THEN 1 ELSE 0 END) as reserved,"
                ." SUM(CASE WHEN {$released} AND NOT {$held} THEN 1 ELSE 0 END) as available,"
                ." SUM(CASE WHEN {$released} AND NOT {$held} AND blood_units.expiry_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_soon",
                [
                    ...$releasedBindings,
                    ...$releasedBindings, ...$heldBindings,
                    ...$releasedBindings, ...$heldBindings,
                    ...$releasedBindings, ...$heldBindings, $operationalDate, $soonUntil,
                ]
            )
            ->first();

        return [
            'available' => (int) ($row?->available ?? 0),
            'expiring_soon' => (int) ($row?->expiring_soon ?? 0),
            'reserved' => (int) ($row?->reserved ?? 0),
            'quarantined' => (int) ($row?->quarantined ?? 0),
        ];
    }

    /**
     * Issuable stock counted by component, blood type and expiry date — the daily stock sheet's cells.
     *
     * Available AND not past its date, the same rule the hospital availability
     * search applies: a unit whose date has passed but which the nightly sweep
     * has not reached yet still says `available`, and counting it would report
     * blood that cannot legally be issued. A unit expiring today still counts;
     * it may be issued until the day ends.
     *
     * @return array<int, array{component_id: int, blood_type_code: string, expiry_date: string, units: int}>
     */
    public function stockReportRows(int $facilityId, string $operationalDate): array
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->where('blood_units.status', BloodUnitStatus::Available->value)
            ->whereDate('blood_units.expiry_date', '>=', $operationalDate)
            ->join('blood_types', 'blood_types.id', '=', 'blood_units.blood_type_id')
            ->groupBy('blood_units.component_id', 'blood_types.code', 'blood_units.expiry_date')
            ->orderBy('blood_units.expiry_date')
            ->selectRaw('blood_units.component_id, blood_types.code as blood_type_code, blood_units.expiry_date, COUNT(*) as units')
            ->get()
            ->map(fn ($row): array => [
                'component_id' => (int) $row->component_id,
                'blood_type_code' => (string) $row->blood_type_code,
                // Normalised: SQLite hands back a date string, Postgres may add a time.
                'expiry_date' => CarbonImmutable::parse($row->expiry_date)->toDateString(),
                'units' => (int) $row->units,
            ])
            ->all();
    }

    /**
     * Issuable stock counted by blood type and component, for threshold monitoring.
     *
     * The same rule as stockReportRows(): available AND not past its date, so a
     * threshold is judged against blood that can legally be issued rather than
     * against a unit the nightly sweep simply has not reached yet.
     *
     * @return array<int, array{blood_type_id: int, component_id: int, units: int}>
     */
    public function issuableCountsByTypeAndComponent(int $facilityId, string $operationalDate): array
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->where('blood_units.status', BloodUnitStatus::Available->value)
            ->whereDate('blood_units.expiry_date', '>=', $operationalDate)
            ->groupBy('blood_units.blood_type_id', 'blood_units.component_id')
            ->selectRaw('blood_units.blood_type_id, blood_units.component_id, COUNT(*) as units')
            ->get()
            ->map(fn ($row): array => [
                'blood_type_id' => (int) $row->blood_type_id,
                'component_id' => (int) $row->component_id,
                'units' => (int) $row->units,
            ])
            ->all();
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
     * Lock the next issuable units for a request, first-expiring-first.
     *
     * The heart of allocation. Every predicate that decides whether a unit may
     * be held is re-asserted here, under the lock, rather than trusted from an
     * earlier read: between a staff member seeing availability and pressing
     * allocate, another facility's request may have taken the bag, the nightly
     * sweep may have expired it, or someone may have discarded it.
     *
     * The claiming-allocation check is a subquery rather than a join so a unit
     * with a cancelled hold still qualifies — giving up a hold returns the bag
     * to stock, and it has to be reachable again.
     *
     * FEFO is applied here and not left to the caller: issuing the newest bag
     * while an older one expires on the shelf is the waste the rule exists to
     * prevent.
     *
     * Must be called inside a transaction.
     *
     * @return Collection<int, BloodUnit>
     */
    public function lockAvailableUnitsFefo(
        int $facilityId,
        int $bloodTypeId,
        int $componentId,
        int $limit,
        string $operationalDate
    ): Collection {
        if ($limit < 1) {
            return new Collection;
        }

        return $this->issuableUnits($facilityId, $bloodTypeId, $componentId, $operationalDate)
            ->limit($limit)
            ->lockForUpdate()
            ->get();
    }

    /**
     * The units staff may pick for a request line: the same set the walk
     * above takes from, first-expiring first, unlocked. Reading holds nothing.
     *
     * @return Collection<int, BloodUnit>
     */
    public function candidateUnits(
        int $facilityId,
        int $bloodTypeId,
        int $componentId,
        string $operationalDate,
        int $limit = 100
    ): Collection {
        return $this->issuableUnits($facilityId, $bloodTypeId, $componentId, $operationalDate)
            ->limit($limit)
            ->get();
    }

    /**
     * Lock the named units of a facility, for a hand-picked allocation.
     *
     * Keyed by id. A unit of another facility is simply missing, which the
     * caller reports as not in this centre's stock.
     *
     * @param  array<int, string>  $unitIds
     * @return Collection<string, BloodUnit>
     */
    public function lockUnitsById(int $facilityId, array $unitIds): Collection
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Those of these units some request already lays claim to.
     *
     * @param  array<int, string>  $unitIds
     * @return array<int, string>
     */
    public function claimedUnitIds(array $unitIds): array
    {
        return RequestAllocation::query()
            ->whereIn('unit_id', $unitIds)
            ->whereIn('status', AllocationStatus::claimingValues())
            ->pluck('unit_id')
            ->all();
    }

    /**
     * Issuable units of one blood type and component: available, in date and
     * held for nobody, first-expiring first.
     *
     * @return Builder<BloodUnit>
     */
    private function issuableUnits(int $facilityId, int $bloodTypeId, int $componentId, string $operationalDate): Builder
    {
        return BloodUnit::query()
            ->forFacility($facilityId)
            ->where('blood_type_id', $bloodTypeId)
            ->where('component_id', $componentId)
            ->where('status', BloodUnitStatus::Available)
            ->whereDate('expiry_date', '>=', $operationalDate)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('request_allocations')
                    ->whereColumn('request_allocations.unit_id', 'blood_units.id')
                    ->whereIn('request_allocations.status', AllocationStatus::claimingValues());
            })
            ->fefo();
    }

    /**
     * Flip locked units to reserved, returning how many rows actually moved.
     *
     * The status predicate is repeated in the WHERE rather than relying on the
     * lock alone, so the statement stays correct if the lock is ever refactored
     * away, and the affected-row count stays a usable reconciliation signal.
     * The caller compares it against what it locked and aborts on a mismatch.
     *
     * @param  array<int, string>  $unitIds
     */
    public function markReserved(array $unitIds): int
    {
        if ($unitIds === []) {
            return 0;
        }

        return BloodUnit::query()
            ->whereIn('id', $unitIds)
            ->where('status', BloodUnitStatus::Available)
            ->update(['status' => BloodUnitStatus::Reserved->value]);
    }

    /**
     * Flip reserved units to issued, returning how many rows actually moved.
     *
     * @param  array<int, string>  $unitIds
     */
    public function markIssued(array $unitIds): int
    {
        if ($unitIds === []) {
            return 0;
        }

        return BloodUnit::query()
            ->whereIn('id', $unitIds)
            ->where('status', BloodUnitStatus::Reserved)
            ->update(['status' => BloodUnitStatus::Issued->value]);
    }

    /**
     * Return reserved units to available stock, for a hold being given up.
     *
     * @param  array<int, string>  $unitIds
     */
    public function markAvailable(array $unitIds): int
    {
        if ($unitIds === []) {
            return 0;
        }

        return BloodUnit::query()
            ->whereIn('id', $unitIds)
            ->where('status', BloodUnitStatus::Reserved)
            ->update(['status' => BloodUnitStatus::Available->value]);
    }

    /**
     * Lock holds whose units have passed their expiry while still reserved.
     *
     * The sweep cannot expire a reserved unit directly — its status says it is
     * promised to a request, and flipping it to expired underneath that promise
     * would leave an allocation pointing at stock nobody can issue. The hold has
     * to be given up first, which is what this finds.
     *
     * Must be called inside a transaction.
     *
     * @return Collection<int, RequestAllocation>
     */
    public function lockExpiredHolds(string $operationalDate): Collection
    {
        return RequestAllocation::query()
            ->where('request_allocations.status', AllocationStatus::Allocated->value)
            ->whereExists(function ($query) use ($operationalDate): void {
                $query->selectRaw('1')
                    ->from('blood_units')
                    ->whereColumn('blood_units.id', 'request_allocations.unit_id')
                    ->where('blood_units.status', BloodUnitStatus::Reserved->value)
                    ->whereDate('blood_units.expiry_date', '<', $operationalDate);
            })
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lock specific units belonging to a facility, whatever their status.
     *
     * @param  array<int, string>  $unitIds
     * @return Collection<int, BloodUnit>
     */
    public function lockUnits(array $unitIds, int $facilityId): Collection
    {
        if ($unitIds === []) {
            return new Collection;
        }

        return BloodUnit::query()
            ->forFacility($facilityId)
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get();
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
            // The unit number or where it is kept, either case: a scanned or
            // typed number arrives as printed, a location as someone recalls it.
            ->when(
                isset($filters['search']),
                function (Builder $query) use ($filters): Builder {
                    $term = '%'.mb_strtolower($filters['search']).'%';

                    return $query->where(fn (Builder $match): Builder => $match
                        ->whereRaw('LOWER(blood_units.id) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(blood_units.storage_location) LIKE ?', [$term]));
                }
            );
    }
}
