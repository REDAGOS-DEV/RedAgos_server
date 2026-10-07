<?php

namespace App\Repository;

use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\HospitalUnit;
use App\Models\TransfusionRequest;
use App\Models\UnitTag;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every read and write of a hospital blood bank's own stock, scoped to one hospital.
 *
 * As in InventoryRepository, the isolation boundary lives here: every method
 * except the sweeps' takes a facility id and applies it, so a caller cannot
 * reach another hospital's shelf or its patients by forgetting a where clause.
 */
class HospitalInventoryRepository
{
    /**
     * What a listed bag carries: its centre-side facts, its active tag, and where it came from.
     *
     * @var array<int, string>
     */
    private const LIST_RELATIONS = [
        'bloodUnit:id,blood_type_id,component_id,volume_ml,expiry_date,direct_distribution_id',
        'bloodUnit.bloodType:id,code',
        'bloodUnit.component:id,name',
        'bloodUnit.directDistribution:id,transfusion_request_id,requested_for,external_blood_source_id,external_unit_number,quantity',
        'bloodUnit.directDistribution.source:id,name',
        'bloodUnit.directDistribution.transfusionRequest:id,reference_number',
        'activeTag.transfusionRequest:id,reference_number',
        'activeTag.patientBloodType:id,code',
        'latestTag',
        'requestAllocation:id,request_id',
        'requestAllocation.request:id,reference_number,transfusion_request_id,weekly_request_id',
        'requestAllocation.request.transfusionRequest:id,reference_number',
    ];

    /**
     * The staff columns a tag's history names.
     *
     * @var array<int, string>
     */
    private const TAG_ACTORS = [
        'taggedBy:id,first_name,last_name',
        'crossmatchedBy:id,first_name,last_name',
        'transfusedBy:id,first_name,last_name',
        'untaggedBy:id,first_name,last_name',
        'returnedBy:id,first_name,last_name',
        'transfusionRequest:id,reference_number',
        'patientBloodType:id,code',
    ];

    /**
     * List a hospital's bags, filtered and FEFO-ordered.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, HospitalUnit>
     */
    public function paginateUnits(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($facilityId, $filters)
            ->with(self::LIST_RELATIONS)
            ->fefo()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Find one bag in this hospital's custody by its bag number.
     *
     * Scoped rather than route-model bound, for the reason given in
     * InventoryRepository::findUnitForFacility(): bag numbers are printed and
     * guessable, so another hospital must get a 404 that reveals nothing.
     */
    public function findUnitForFacility(string $unitId, int $facilityId): ?HospitalUnit
    {
        return HospitalUnit::query()
            ->forFacility($facilityId)
            ->where('hospital_units.unit_id', $unitId)
            ->with(self::LIST_RELATIONS)
            ->first();
    }

    /**
     * Re-read a bag under a row lock for a write that must not race.
     *
     * The bag is always locked before its tag, by staff writes and the sweep
     * alike, so the two cannot deadlock. Must be called inside a transaction.
     */
    public function lockUnitForFacility(string $unitId, int $facilityId): ?HospitalUnit
    {
        return HospitalUnit::query()
            ->forFacility($facilityId)
            ->where('hospital_units.unit_id', $unitId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Lock the tag currently holding a bag, if any.
     *
     * Must be called inside a transaction, after the bag itself is locked.
     */
    public function lockActiveTag(int $hospitalUnitId): ?UnitTag
    {
        return UnitTag::query()
            ->where('hospital_unit_id', $hospitalUnitId)
            ->active()
            ->lockForUpdate()
            ->first();
    }

    /**
     * Lock a bag's most recent tag, whatever its status.
     *
     * A bag pending return is explained by its last tag, which has already
     * ended; confirming the return stamps that row.
     */
    public function lockLatestTag(int $hospitalUnitId): ?UnitTag
    {
        return UnitTag::query()
            ->where('hospital_unit_id', $hospitalUnitId)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find one of this hospital's Patient Transfusion Requests, for linking a tag to it.
     */
    public function findTransfusionRequestFor(int $transfusionRequestId, int $facilityId): ?TransfusionRequest
    {
        return TransfusionRequest::query()
            ->raisedBy($facilityId)
            ->whereKey($transfusionRequestId)
            ->first();
    }

    /**
     * Put received bags into a hospital's custody as available stock.
     *
     * A bag received by direct distribution has no dispatched hold, so its
     * request_allocation_id is null.
     *
     * @param  array<int, array{unit_id: string, request_allocation_id: int|null}>  $bags
     * @return Collection<int, HospitalUnit>
     */
    public function createUnits(int $facilityId, array $bags): Collection
    {
        return new Collection(array_map(
            fn (array $bag): HospitalUnit => HospitalUnit::query()->create([
                'facility_id' => $facilityId,
                'unit_id' => $bag['unit_id'],
                'request_allocation_id' => $bag['request_allocation_id'],
                'status' => HospitalUnitStatus::Available,
            ]),
            $bags
        ));
    }

    /**
     * Count a hospital's bags by status, projecting every status.
     *
     * @return array<string, int>
     */
    public function summaryCounts(int $facilityId): array
    {
        $counts = HospitalUnit::query()
            ->forFacility($facilityId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        $totals = [];

        foreach (HospitalUnitStatus::values() as $status) {
            $totals[$status] = (int) ($counts[$status] ?? 0);
        }

        return $totals;
    }

    /**
     * Available bags per blood type.
     *
     * @return array<int, array{blood_type_id: int, code: string, available: int}>
     */
    public function countsByBloodType(int $facilityId): array
    {
        return HospitalUnit::query()
            ->forFacility($facilityId)
            ->where('hospital_units.status', HospitalUnitStatus::Available->value)
            ->join('blood_units', 'blood_units.id', '=', 'hospital_units.unit_id')
            ->join('blood_types', 'blood_types.id', '=', 'blood_units.blood_type_id')
            ->groupBy('blood_types.id', 'blood_types.code')
            ->orderBy('blood_types.id')
            ->selectRaw('blood_types.id as blood_type_id, blood_types.code as code, COUNT(*) as available')
            ->get()
            ->map(fn ($row): array => [
                'blood_type_id' => (int) $row->blood_type_id,
                'code' => (string) $row->code,
                'available' => (int) $row->available,
            ])
            ->all();
    }

    /**
     * Available bags whose date falls within the next few days, today included.
     */
    public function expiringWithinCount(int $facilityId, string $operationalDate, int $days): int
    {
        return HospitalUnit::query()
            ->forFacility($facilityId)
            ->where('hospital_units.status', HospitalUnitStatus::Available->value)
            ->whereHas('bloodUnit', fn (Builder $bag): Builder => $bag->whereBetween('expiry_date', [
                $operationalDate,
                CarbonImmutable::parse($operationalDate)->addDays($days)->toDateString(),
            ]))
            ->count();
    }

    /**
     * Active tags at this hospital whose period has already run out.
     *
     * Should be zero within a minute of any deadline. A number that stays
     * above it means the tag sweep is not running, which is a deployment
     * fault to surface rather than paper over.
     */
    public function overdueActiveTagCount(int $facilityId, CarbonImmutable $now): int
    {
        return $this->overdue(UnitTag::query()->where('facility_id', $facilityId), $now)->count();
    }

    /**
     * List the tags that ended at this hospital — untagged or transfused — newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, UnitTag>
     */
    public function paginateTagEvents(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        $ended = [
            UnitTagStatus::Transfused->value,
            UnitTagStatus::UntaggedAssigned->value,
            UnitTagStatus::UntaggedCrossmatched->value,
        ];

        return UnitTag::query()
            ->where('unit_tags.facility_id', $facilityId)
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('unit_tags.status', $filters['status']),
                fn (Builder $query): Builder => $query->whereIn('unit_tags.status', $ended)
            )
            ->when(
                isset($filters['untag_reason']),
                fn (Builder $query): Builder => $query->where('unit_tags.untag_reason', $filters['untag_reason'])
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $this->searchTags($query, (string) $filters['search'])
            )
            ->with([
                ...self::TAG_ACTORS,
                'hospitalUnit:id,unit_id,status',
                'hospitalUnit.bloodUnit:id,blood_type_id,component_id,direct_distribution_id',
                'hospitalUnit.bloodUnit.directDistribution:id,external_unit_number,quantity',
                'hospitalUnit.bloodUnit.bloodType:id,code',
                'hospitalUnit.bloodUnit.component:id,name',
            ])
            ->orderByRaw('COALESCE(unit_tags.untagged_at, unit_tags.transfused_at) DESC')
            ->orderByDesc('unit_tags.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Every tag ever placed on one bag, newest first, with the staff who acted on it.
     *
     * @return Collection<int, UnitTag>
     */
    public function tagHistory(int $hospitalUnitId): Collection
    {
        return UnitTag::query()
            ->where('hospital_unit_id', $hospitalUnitId)
            ->with(self::TAG_ACTORS)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Active tags whose period has run out, across every hospital.
     *
     * Deliberately NOT facility-scoped: the tag sweep is a system actor. Its
     * result is a list of CANDIDATES — a crossmatch or a staff release can
     * land between this select and the update — so pass the ids through
     * lockConfirmedOverdueTags() before writing anything.
     *
     * @return Builder<UnitTag>
     */
    public function overdueTags(CarbonImmutable $now): Builder
    {
        return $this->overdue(UnitTag::query(), $now);
    }

    /**
     * Lock hospital bags by id, in id order, whatever their status or hospital.
     *
     * The sweep takes the bags first, as a staff write does, so the two lock
     * in the same order and cannot deadlock. Must be called inside a
     * transaction.
     *
     * @param  array<int, int>  $hospitalUnitIds
     * @return Collection<int, HospitalUnit>
     */
    public function lockUnitsById(array $hospitalUnitIds): Collection
    {
        if ($hospitalUnitIds === []) {
            return new Collection;
        }

        return HospitalUnit::query()
            ->whereIn('id', $hospitalUnitIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Re-assert the overdue predicates under a row lock.
     *
     * Status and deadline both: a tag crossmatched since the candidate select
     * has a new status and a new, later deadline, and must not be untagged on
     * the strength of the old one. Must be called inside a transaction.
     *
     * @param  array<int, int>  $candidateIds
     * @return Collection<int, UnitTag>
     */
    public function lockConfirmedOverdueTags(array $candidateIds, CarbonImmutable $now): Collection
    {
        if ($candidateIds === []) {
            return new Collection;
        }

        return $this->overdue(UnitTag::query()->whereIn('id', $candidateIds), $now)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * End confirmed overdue tags of one status, returning how many rows moved.
     *
     * The predicates ride in the WHERE as well, so the statement stays correct
     * if the lock is ever refactored away, and the caller reconciles the count
     * against what it confirmed.
     *
     * @param  array<int, int>  $tagIds
     */
    public function markTagsUntagged(array $tagIds, UnitTagStatus $from, CarbonImmutable $now): int
    {
        if ($tagIds === []) {
            return 0;
        }

        return UnitTag::query()
            ->whereIn('id', $tagIds)
            ->where('status', $from->value)
            ->where($this->deadlineColumn($from), '<=', $now)
            ->update([
                'status' => $from->untaggedState()?->value,
                'untagged_at' => $now,
                'untagged_by' => null,
                'untag_reason' => UntagReason::deadlineFor($from)?->value,
                'updated_at' => $now,
            ]);
    }

    /**
     * Move bags from one status to another, returning how many rows moved.
     *
     * @param  array<int, int>  $hospitalUnitIds
     */
    public function markUnits(array $hospitalUnitIds, HospitalUnitStatus $from, HospitalUnitStatus $to, CarbonImmutable $at): int
    {
        if ($hospitalUnitIds === []) {
            return 0;
        }

        return HospitalUnit::query()
            ->whereIn('id', $hospitalUnitIds)
            ->where('status', $from->value)
            ->update([
                'status' => $to->value,
                'updated_at' => $at,
            ]);
    }

    /**
     * Available hospital bags past their date, across every hospital.
     *
     * Deliberately NOT facility-scoped, and CANDIDATES only, exactly as
     * InventoryRepository::dueUnits(). Touches the hospital's side of the bag
     * only: the centre's blood_units row stays `issued`.
     *
     * @return Builder<HospitalUnit>
     */
    public function dueUnits(string $operationalDate): Builder
    {
        return HospitalUnit::query()
            ->where('hospital_units.status', HospitalUnitStatus::Available->value)
            ->whereHas('bloodUnit', fn (Builder $bag): Builder => $bag->whereDate('expiry_date', '<', $operationalDate));
    }

    /**
     * Re-assert both due predicates under a row lock.
     *
     * Must be called inside a transaction.
     *
     * @param  array<int, int>  $candidateIds
     * @return Collection<int, HospitalUnit>
     */
    public function lockConfirmedDueUnits(array $candidateIds, string $operationalDate): Collection
    {
        if ($candidateIds === []) {
            return new Collection;
        }

        return $this->dueUnits($operationalDate)
            ->whereIn('hospital_units.id', $candidateIds)
            ->with('bloodUnit:id,expiry_date')
            ->orderBy('hospital_units.id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Flip confirmed hospital bags to expired, returning how many rows moved.
     *
     * @param  array<int, int>  $confirmedIds
     */
    public function markExpired(array $confirmedIds, string $operationalDate, CarbonImmutable $sweptAt): int
    {
        if ($confirmedIds === []) {
            return 0;
        }

        return $this->dueUnits($operationalDate)
            ->whereIn('hospital_units.id', $confirmedIds)
            ->update([
                'status' => HospitalUnitStatus::Expired->value,
                'expired_at' => $sweptAt,
                'updated_at' => $sweptAt,
            ]);
    }

    /**
     * Limit a tag query to active tags whose period has run out by the given moment.
     *
     * At the deadline counts as past it: a tag placed at 08:00 has until
     * 07:59:59 the next day.
     *
     * @param  Builder<UnitTag>  $query
     * @return Builder<UnitTag>
     */
    private function overdue(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->where(function (Builder $overdue) use ($now): void {
            $overdue
                ->where(fn (Builder $assigned): Builder => $assigned
                    ->where('unit_tags.status', UnitTagStatus::TagAssigned->value)
                    ->where('unit_tags.crossmatch_deadline_at', '<=', $now))
                ->orWhere(fn (Builder $crossmatched): Builder => $crossmatched
                    ->where('unit_tags.status', UnitTagStatus::TagCrossmatched->value)
                    ->where('unit_tags.transfusion_deadline_at', '<=', $now));
        });
    }

    /**
     * The deadline an active tag status runs against.
     */
    private function deadlineColumn(UnitTagStatus $status): string
    {
        return $status === UnitTagStatus::TagCrossmatched
            ? 'transfusion_deadline_at'
            : 'crossmatch_deadline_at';
    }

    /**
     * Match tags by bag number or patient name.
     *
     * @param  Builder<UnitTag>  $query
     * @return Builder<UnitTag>
     */
    private function searchTags(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(fn (Builder $search): Builder => $search
            ->where('unit_tags.patient_surname', 'like', $like)
            ->orWhere('unit_tags.patient_first_name', 'like', $like)
            ->orWhere('unit_tags.patient_record_number', 'like', $like)
            ->orWhereHas('hospitalUnit', fn (Builder $unit): Builder => $unit->where('unit_id', 'like', $like))
            ->orWhereHas(
                'hospitalUnit.bloodUnit.directDistribution',
                fn (Builder $delivery): Builder => $delivery->where('external_unit_number', 'like', $like)
            ));
    }

    /**
     * Apply the listing filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<HospitalUnit>
     */
    private function filtered(int $facilityId, array $filters): Builder
    {
        return HospitalUnit::query()
            ->forFacility($facilityId)
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('hospital_units.status', $filters['status'])
            )
            ->when(
                isset($filters['blood_type_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'bloodUnit',
                    fn (Builder $bag): Builder => $bag->where('blood_type_id', $filters['blood_type_id'])
                )
            )
            ->when(
                isset($filters['component_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'bloodUnit',
                    fn (Builder $bag): Builder => $bag->where('component_id', $filters['component_id'])
                )
            )
            ->when(
                isset($filters['expiring_within_days']),
                fn (Builder $query): Builder => $query
                    ->where('hospital_units.status', HospitalUnitStatus::Available->value)
                    ->whereHas('bloodUnit', fn (Builder $bag): Builder => $bag->whereBetween('expiry_date', [
                        $filters['operational_date'],
                        CarbonImmutable::parse($filters['operational_date'])
                            ->addDays((int) $filters['expiring_within_days'])
                            ->toDateString(),
                    ]))
            )
            // A patient's bags: those received for their requirement, and those
            // tagged to it from the hospital's own shelf.
            ->when(
                isset($filters['transfusion_request_id']),
                fn (Builder $query): Builder => $query->where(fn (Builder $patient): Builder => $patient
                    ->whereHas(
                        'requestAllocation.request',
                        fn (Builder $request): Builder => $request->where('transfusion_request_id', $filters['transfusion_request_id'])
                    )
                    ->orWhereHas(
                        'tags',
                        fn (Builder $tag): Builder => $tag->where('transfusion_request_id', $filters['transfusion_request_id'])
                    )
                    // Bags received from outside RedAgos for this patient.
                    ->orWhereHas(
                        'bloodUnit.directDistribution',
                        fn (Builder $delivery): Builder => $delivery->where('transfusion_request_id', $filters['transfusion_request_id'])
                    ))
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->where(fn (Builder $search): Builder => $search
                    ->where('hospital_units.unit_id', 'like', '%'.$filters['search'].'%')
                    ->orWhereHas(
                        'bloodUnit.directDistribution',
                        fn (Builder $delivery): Builder => $delivery->where('external_unit_number', 'like', '%'.$filters['search'].'%')
                    )
                    ->orWhereHas('activeTag', fn (Builder $tag): Builder => $tag->where(fn (Builder $patient): Builder => $patient
                        ->where('patient_surname', 'like', '%'.$filters['search'].'%')
                        ->orWhere('patient_first_name', 'like', '%'.$filters['search'].'%')
                        ->orWhere('patient_record_number', 'like', '%'.$filters['search'].'%'))))
            );
    }
}
