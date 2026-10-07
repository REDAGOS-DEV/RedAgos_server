<?php

namespace App\Repository;

use App\Models\ReplenishmentSchedule;
use App\Models\WeeklyRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every read and write of a hospital's request schedules and weekly requests.
 *
 * Every method takes the hospital's facility id and applies it, so a caller
 * cannot reach another hospital's schedule or orders by forgetting a where
 * clause.
 */
class WeeklyRequestRepository
{
    /**
     * What a weekly request's header shows beside its blood requests.
     *
     * @var array<int, string>
     */
    private const HEADER_RELATIONS = [
        'targetFacility:id,name,address',
        'requester:id,first_name,last_name',
    ];

    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository
    ) {}

    /**
     * A hospital's request schedules, one per centre, with the centre named.
     *
     * @return Collection<int, ReplenishmentSchedule>
     */
    public function schedulesFor(int $facilityId): Collection
    {
        return ReplenishmentSchedule::query()
            ->forFacility($facilityId)
            ->with(['targetFacility:id,name,address', 'updatedBy:id,first_name,last_name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * A hospital's request schedule for one centre, if it keeps one.
     */
    public function scheduleFor(int $facilityId, int $targetFacilityId): ?ReplenishmentSchedule
    {
        return ReplenishmentSchedule::query()
            ->forFacility($facilityId)
            ->where('target_facility_id', $targetFacilityId)
            ->with(['targetFacility:id,name,address', 'updatedBy:id,first_name,last_name'])
            ->first();
    }

    /**
     * Whether the hospital already sent this centre its weekly request for the given day.
     */
    public function existsFor(int $facilityId, int $targetFacilityId, string $requestDay): bool
    {
        return WeeklyRequest::query()
            ->raisedBy($facilityId)
            ->where('target_facility_id', $targetFacilityId)
            ->whereDate('request_day', $requestDay)
            ->exists();
    }

    /**
     * Derive the next weekly request reference for a hospital.
     *
     * WR-{hospital}-NNNN, parsed rather than taken from a lexicographic MAX,
     * for the reason BloodRequestRepository::nextReference() gives. Call under
     * BloodRequestRepository::lockFacility().
     */
    public function nextReference(int $facilityId): string
    {
        $prefix = "WR-{$facilityId}-";
        $highest = 0;

        $existing = WeeklyRequest::query()
            ->where('reference_number', 'like', $prefix.'%')
            ->pluck('reference_number');

        foreach ($existing as $reference) {
            $suffix = substr($reference, strlen($prefix));

            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * List a hospital's weekly requests, newest request day first, with every blood request projected.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WeeklyRequest>
     */
    public function paginateRaisedBy(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return WeeklyRequest::query()
            ->raisedBy($facilityId)
            ->when(
                isset($filters['target_facility_id']),
                fn (Builder $query): Builder => $query->where('target_facility_id', $filters['target_facility_id'])
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->where(fn (Builder $search): Builder => $search
                    ->where('reference_number', 'like', '%'.$filters['search'].'%')
                    ->orWhereHas(
                        'bloodRequests',
                        fn (Builder $request): Builder => $request->where('reference_number', 'like', '%'.$filters['search'].'%')
                    ))
            )
            ->with([
                ...self::HEADER_RELATIONS,
                ...$this->bloodRequestRepository->projectionRelationsUnder('bloodRequests'),
            ])
            ->orderByDesc('request_day')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Find one of a hospital's weekly requests, with its blood requests and every bag dispatched for them.
     */
    public function findRaisedBy(int $weeklyRequestId, int $facilityId): ?WeeklyRequest
    {
        return WeeklyRequest::query()
            ->raisedBy($facilityId)
            ->whereKey($weeklyRequestId)
            ->with([
                ...self::HEADER_RELATIONS,
                ...$this->bloodRequestRepository->projectionRelationsUnder('bloodRequests', withUnits: true),
            ])
            ->first();
    }

    /**
     * The weekly requests a hospital sent from a given day on, for working out which request days were missed.
     *
     * @return Collection<int, WeeklyRequest>
     */
    public function sentSince(int $facilityId, string $fromDate): Collection
    {
        return WeeklyRequest::query()
            ->raisedBy($facilityId)
            ->whereDate('request_day', '>=', $fromDate)
            ->get(['id', 'reference_number', 'target_facility_id', 'request_day']);
    }
}
