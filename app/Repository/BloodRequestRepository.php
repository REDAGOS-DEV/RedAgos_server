<?php

namespace App\Repository;

use App\Models\BloodRequest;
use App\Models\Facility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
     * The relations every request projection needs.
     *
     * @var array<int, string>
     */
    private const PROJECTION_RELATIONS = [
        'bloodType',
        'component',
        'requestingFacility',
        'targetFacility',
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
                isset($filters['blood_type_id']),
                fn (Builder $query): Builder => $query->where('blood_type_id', $filters['blood_type_id'])
            )
            ->when(
                isset($filters['component_id']),
                fn (Builder $query): Builder => $query->where('component_id', $filters['component_id'])
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->where('reference_number', 'like', '%'.$filters['search'].'%')
            );
    }
}
