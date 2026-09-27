<?php

namespace App\Repository;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\RequestPurpose;
use App\Models\BloodRequest;
use App\Models\Facility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every read and write of blood requests, scoped to one side of the exchange.
 *
 * A request has two facilities and they are never interchangeable, so no method
 * here takes an unqualified "facility id". Each one says whether it means the
 * facility that raised the request or the facility it was addressed to, and the
 * caller has to choose.
 */
class BloodRequestRepository
{
    /**
     * How far back a walk-in looks for a request for the same patient.
     *
     * Matching on a name alone is weak evidence, so it is bounded: a patient of
     * the same name treated two months ago is not today's duplicate. A matching
     * reference number is not bounded — that is the same request.
     */
    public const DUPLICATE_WINDOW_DAYS = 14;

    /**
     * The relations every request projection needs.
     *
     * @var array<int, string>
     */
    private const PROJECTION_RELATIONS = [
        'bloodType',
        'items.component',
        // What other facilities were asked to supply of each line, and whether
        // they are still on it. RequestStatusResolver reads this to work out
        // forwarded and remaining quantities.
        'items.followUpItems.request:id,status',
        'requestingFacility',
        'targetFacility',
        // Every hold, so each row's per-line fulfilment can be worked out in
        // PHP. A page of requests holds a handful of bags each; one query for
        // all of them is cheaper than an aggregate per figure.
        'allocations',
        'requester:id,first_name,last_name',
        'recorder:id,first_name,last_name',
        'walkIn',
        'walkIn.verificationRecorder:id,first_name,last_name',
        'parent:id,reference_number,target_facility_id,status,closed_at',
        'parent.targetFacility:id,name,address',
        'followUps:id,parent_request_id,reference_number,target_facility_id,status,closed_at',
        'followUps.targetFacility:id,name,address',
    ];

    /**
     * Coverage counts every projection needs, as SQL aggregates.
     *
     * Counted in the query rather than from a loaded allocations relation,
     * because a listing does not load one — deriving coverage in PHP would
     * silently report every row as nothing-allocated, and a queue that says a
     * request is uncovered when it is not is worse than no number at all.
     *
     * @return array<string, callable>
     */
    private function coverageCounts(): array
    {
        return [
            'allocations as allocated_count' => fn ($query) => $query->claiming(),
            'allocations as received_count' => fn ($query) => $query->claiming()->whereNotNull('received_at'),
        ];
    }

    /**
     * List requests one facility raised.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, BloodRequest>
     */
    public function paginateRaisedBy(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->raisedBy($facilityId)
            ->with(self::PROJECTION_RELATIONS)
            ->withCount($this->coverageCounts())
            ->orderByDesc('request_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * List requests addressed to one facility, emergencies first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, BloodRequest>
     */
    public function paginateAddressedTo(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->addressedTo($facilityId)
            ->with(self::PROJECTION_RELATIONS)
            ->withCount($this->coverageCounts())
            ->triaged()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Find one request the given facility raised.
     *
     * Scoped rather than resolved by route-model binding, so a request
     * belonging to another facility returns a 404 that reveals nothing about
     * whether the id exists.
     */
    public function findRaisedBy(int $requestId, int $facilityId): ?BloodRequest
    {
        return BloodRequest::query()
            ->raisedBy($facilityId)
            ->whereKey($requestId)
            ->with([...self::PROJECTION_RELATIONS, 'allocations.unit'])
            ->first();
    }

    /**
     * Find one request by its reference number, for the facility that raised it.
     */
    public function findByReferenceFor(string $reference, int $facilityId): ?BloodRequest
    {
        return BloodRequest::query()
            ->raisedBy($facilityId)
            ->where('reference_number', $reference)
            ->with([...self::PROJECTION_RELATIONS, 'allocations.unit'])
            ->first();
    }

    /**
     * Find one request addressed to the given facility.
     */
    public function findAddressedTo(int $requestId, int $facilityId): ?BloodRequest
    {
        return BloodRequest::query()
            ->addressedTo($facilityId)
            ->whereKey($requestId)
            ->with([...self::PROJECTION_RELATIONS, 'allocations.unit'])
            ->first();
    }

    /**
     * Re-read a request under a row lock for a write that must not race.
     *
     * Two staff deciding the same request at once is the case this serialises.
     * Must be called inside a transaction; a lock taken outside one is released
     * immediately and proves nothing.
     */
    public function lockAddressedTo(int $requestId, int $facilityId): ?BloodRequest
    {
        return BloodRequest::query()
            ->addressedTo($facilityId)
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Re-read a request under a row lock for the facility that raised it.
     */
    public function lockRaisedBy(int $requestId, int $facilityId): ?BloodRequest
    {
        return BloodRequest::query()
            ->raisedBy($facilityId)
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Lock the parent of a follow-up, whichever facility it was addressed to.
     *
     * The one unscoped lock here, and only ever reached from a request the
     * caller already holds: a follow-up's parent has to be re-settled when the
     * follow-up is refused or withdrawn, because the quantity it was carrying
     * comes back to the parent. Nothing about the parent is returned to the
     * caller from this.
     */
    public function lockParentOf(BloodRequest $request): ?BloodRequest
    {
        if ($request->parent_request_id === null) {
            return null;
        }

        return BloodRequest::query()
            ->whereKey($request->parent_request_id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find one request a hospital raised, with everything a projection needs.
     *
     * Used where a centre acts on a request addressed elsewhere without being
     * shown it: the parent of a walk-in follow-up.
     */
    public function findForHospital(int $requestId, int $hospitalId): ?BloodRequest
    {
        return BloodRequest::query()
            ->raisedBy($hospitalId)
            ->whereKey($requestId)
            ->with(self::PROJECTION_RELATIONS)
            ->first();
    }

    /**
     * Load everything a projection needs onto requests already in hand.
     */
    public function loadForProjection(BloodRequest $request, bool $withUnits = false): BloodRequest
    {
        return $request->load($withUnits
            ? [...self::PROJECTION_RELATIONS, 'allocations.unit']
            : self::PROJECTION_RELATIONS);
    }

    /**
     * Find a hospital's still-active requests for one patient.
     *
     * This is the duplicate check behind a walk-in. It matches on the reference
     * number the watcher carries, or on the patient's name — and blood type,
     * when given — within the last fortnight. Name matching is case-insensitive
     * because the same patient is typed differently at two counters.
     *
     * Refused, withdrawn and completed requests are left out: a patient who
     * needs blood again after a finished request is not a duplicate.
     *
     * @param  array<string, mixed>  $criteria
     * @return Collection<int, BloodRequest>
     */
    public function patientMatches(int $hospitalId, array $criteria, ?int $excludeRequestId = null): Collection
    {
        $surname = mb_strtolower(trim((string) ($criteria['patient_surname'] ?? '')));
        $firstName = mb_strtolower(trim((string) ($criteria['patient_first_name'] ?? '')));
        $reference = trim((string) ($criteria['presented_reference'] ?? ''));
        $bloodTypeId = $criteria['blood_type_id'] ?? null;

        if ($reference === '' && ($surname === '' || $firstName === '')) {
            return new Collection;
        }

        return BloodRequest::query()
            ->raisedBy($hospitalId)
            ->where('request_purpose', RequestPurpose::PatientTransfusion->value)
            ->whereIn('status', [
                BloodRequestStatus::Pending->value,
                BloodRequestStatus::Processing->value,
                BloodRequestStatus::Partial->value,
            ])
            ->when($excludeRequestId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeRequestId))
            ->where(function (Builder $query) use ($reference, $surname, $firstName, $bloodTypeId): void {
                if ($reference !== '') {
                    $query->orWhere('reference_number', $reference);
                }

                if ($surname !== '' && $firstName !== '') {
                    $query->orWhere(function (Builder $byName) use ($surname, $firstName, $bloodTypeId): void {
                        $byName->whereRaw('LOWER(patient_surname) = ?', [$surname])
                            ->whereRaw('LOWER(patient_first_name) = ?', [$firstName])
                            ->where('request_date', '>=', now()->subDays(self::DUPLICATE_WINDOW_DAYS))
                            ->when($bloodTypeId !== null, fn (Builder $typed): Builder => $typed->where('blood_type_id', $bloodTypeId));
                    });
                }
            })
            ->with(self::PROJECTION_RELATIONS)
            ->orderByDesc('request_date')
            ->limit(10)
            ->get();
    }

    /**
     * Derive the next reference number for a hospital.
     *
     * Shared by portal submissions, walk-ins and follow-ups, so every request a
     * hospital is responsible for sits in one RQ-{hospital}-NNNN sequence
     * whichever counter it was keyed in at. Call under lockFacility().
     *
     * The sequence is parsed in PHP rather than taken from a lexicographic MAX,
     * for the same reason InventoryService derives unit ids that way: as a
     * string, 'RQ-4-100' sorts before 'RQ-4-99', so the maximum stops being the
     * latest once a facility passes its ninety-ninth request.
     */
    public function nextReference(int $facilityId): string
    {
        $prefix = "RQ-{$facilityId}-";
        $highest = 0;

        foreach ($this->existingReferences($prefix) as $reference) {
            $suffix = substr($reference, strlen($prefix));

            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Take a row lock on the requesting facility to serialise reference numbering.
     *
     * The facility row is what concurrent submissions from the same blood bank
     * have to queue behind, because the reference sequence is namespaced by
     * facility. Must be called inside a transaction.
     */
    public function lockFacility(int $facilityId): ?Facility
    {
        return Facility::query()
            ->whereKey($facilityId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The reference numbers already issued under a facility's prefix.
     *
     * Returned as strings rather than a count so the sequence can be derived by
     * parsing, not by counting: a deleted or manually-numbered row would make a
     * count produce a number already taken.
     *
     * @return array<int, string>
     */
    public function existingReferences(string $prefix): array
    {
        return BloodRequest::query()
            ->where('reference_number', 'like', $prefix.'%')
            ->pluck('reference_number')
            ->all();
    }

    /**
     * Whether any of these reference numbers already exist.
     *
     * Not facility-scoped, because reference_number is globally unique.
     *
     * @param  array<int, string>  $references
     * @return array<int, string>
     */
    public function existingReferencesAmong(array $references): array
    {
        if ($references === []) {
            return [];
        }

        return BloodRequest::query()
            ->whereIn('reference_number', $references)
            ->pluck('reference_number')
            ->all();
    }

    /**
     * Apply the listing filters both sides share.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<BloodRequest>
     */
    private function filtered(array $filters): Builder
    {
        return BloodRequest::query()
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['urgency_level']),
                fn (Builder $query): Builder => $query->where('urgency_level', $filters['urgency_level'])
            )
            ->when(
                isset($filters['request_source']),
                fn (Builder $query): Builder => $query->where('request_source', $filters['request_source'])
            )
            ->when(
                isset($filters['blood_type_id']),
                fn (Builder $query): Builder => $query->where('blood_type_id', $filters['blood_type_id'])
            )
            // The component moved onto the request's lines, so filtering by
            // one now asks whether any line names it.
            ->when(
                isset($filters['component_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'items',
                    fn (Builder $items): Builder => $items->where('component_id', $filters['component_id'])
                )
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->where('reference_number', 'like', '%'.$filters['search'].'%')
            )
            // The dispatch queue: requests with stock held but not yet sent.
            // Expressed as a filter rather than left to the client, which could
            // only narrow the page it happened to be shown.
            ->when(
                isset($filters['awaiting_release']) && $filters['awaiting_release'],
                fn (Builder $query): Builder => $query->whereHas(
                    'allocations',
                    fn (Builder $allocations): Builder => $allocations->where('status', AllocationStatus::Allocated->value)
                )
            )
            // Requests with units dispatched that the hospital has not yet
            // confirmed arrived.
            ->when(
                isset($filters['awaiting_receipt']) && $filters['awaiting_receipt'],
                fn (Builder $query): Builder => $query->whereHas(
                    'allocations',
                    fn (Builder $allocations): Builder => $allocations
                        ->where('status', AllocationStatus::Released->value)
                        ->whereNull('received_at')
                )
            );
    }
}
