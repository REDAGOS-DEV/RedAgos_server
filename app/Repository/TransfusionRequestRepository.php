<?php

namespace App\Repository;

use App\Enums\BloodRequestStatus;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every read and write of Patient Transfusion Requests, scoped to the hospital that raised them.
 *
 * A requirement belongs to one hospital blood bank. The centres asked for
 * shares of it see those shares — their facility allocations, through
 * BloodRequestRepository — and never the requirement's other allocations, so
 * nothing here is scoped to a centre.
 */
class TransfusionRequestRepository
{
    /**
     * What TransfusionRequestResolver needs to work out the figures.
     *
     * @var array<int, string>
     */
    public const FIGURE_RELATIONS = [
        'items',
        'facilityAllocations.items',
        'facilityAllocations.allocations',
    ];

    /**
     * What a listing row needs.
     *
     * @var array<int, string>
     */
    private const LIST_RELATIONS = [
        ...self::FIGURE_RELATIONS,
        'bloodType',
        'hospital',
        'items.component',
        'requester:id,first_name,last_name',
        'recorder:id,first_name,last_name',
        'facilityAllocations.targetFacility',
    ];

    /**
     * What the full view of one requirement needs, including each allocation's own projection.
     *
     * @var array<int, string>
     */
    private const DETAIL_RELATIONS = [
        ...self::LIST_RELATIONS,
        'items.closer:id,first_name,last_name',
        'facilityAllocations.items.component',
        'facilityAllocations.allocations.unit',
        'facilityAllocations.bloodType',
        'facilityAllocations.requestingFacility',
        'facilityAllocations.requester:id,first_name,last_name',
        'facilityAllocations.recorder:id,first_name,last_name',
        'facilityAllocations.walkIn',
        'facilityAllocations.walkIn.verificationRecorder:id,first_name,last_name',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TransfusionRequest>
     */
    public function paginateRaisedBy(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return TransfusionRequest::query()
            ->raisedBy($facilityId)
            ->when(isset($filters['status']), fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when(isset($filters['urgency_level']), fn (Builder $query): Builder => $query->where('urgency_level', $filters['urgency_level']))
            ->when(isset($filters['request_source']), fn (Builder $query): Builder => $query->where('request_source', $filters['request_source']))
            ->when(isset($filters['search']), function (Builder $query) use ($filters): Builder {
                $search = '%'.$filters['search'].'%';

                return $query->where(fn (Builder $inner): Builder => $inner
                    ->where('reference_number', 'like', $search)
                    ->orWhere('patient_surname', 'like', $search)
                    ->orWhere('patient_first_name', 'like', $search));
            })
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findRaisedBy(int $id, int $facilityId): ?TransfusionRequest
    {
        return TransfusionRequest::query()
            ->raisedBy($facilityId)
            ->whereKey($id)
            ->with(self::DETAIL_RELATIONS)
            ->first();
    }

    /**
     * Find a requirement by its own PTR reference or by the RQ reference of any of its allocations.
     *
     * The watcher's paperwork may carry either, and both lead to the same
     * patient.
     */
    public function findByReferenceFor(string $reference, int $facilityId): ?TransfusionRequest
    {
        $id = TransfusionRequest::query()->raisedBy($facilityId)->where('reference_number', $reference)->value('id')
            ?? BloodRequest::query()->raisedBy($facilityId)->where('reference_number', $reference)->value('transfusion_request_id');

        return $id === null ? null : $this->findRaisedBy((int) $id, $facilityId);
    }

    /**
     * Load everything the full view needs onto a requirement already in hand.
     */
    public function loadForProjection(TransfusionRequest $request): TransfusionRequest
    {
        return $request->load(self::DETAIL_RELATIONS);
    }

    /**
     * Re-read a requirement under a row lock, for the hospital that raised it.
     */
    public function lockRaisedBy(int $id, int $facilityId): ?TransfusionRequest
    {
        return TransfusionRequest::query()
            ->raisedBy($facilityId)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Lock a requirement by id, whoever is acting on it.
     *
     * Reached only from an allocation the caller already holds locked, to
     * re-derive the requirement it belongs to. Nothing about the requirement is
     * returned to the caller from this.
     */
    public function lockById(int $id): ?TransfusionRequest
    {
        return TransfusionRequest::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Lock every facility allocation of a requirement, in id order.
     *
     * Taken before the requirement itself whenever both are needed, which is
     * the order every allocation write takes them in — allocation, then
     * requirement — so the two paths can never wait on each other.
     *
     * @return Collection<int, BloodRequest>
     */
    public function lockAllocationsOf(int $id): Collection
    {
        return BloodRequest::query()
            ->where('transfusion_request_id', $id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Find a hospital's active requirements for one patient.
     *
     * The duplicate check behind a walk-in and behind the portal form. It
     * matches a PTR reference — or an allocation's RQ reference — exactly, or
     * the patient's name, and blood type when given, within the last
     * fortnight. Fulfilled and cancelled requirements are left out: a patient
     * who needs blood again after a finished request is not a duplicate.
     *
     * @param  array<string, mixed>  $criteria
     * @return Collection<int, TransfusionRequest>
     */
    public function patientMatches(int $hospitalId, array $criteria, ?int $excludeId = null): Collection
    {
        $surname = mb_strtolower(trim((string) ($criteria['patient_surname'] ?? '')));
        $firstName = mb_strtolower(trim((string) ($criteria['patient_first_name'] ?? '')));
        $reference = trim((string) ($criteria['presented_reference'] ?? ''));
        $bloodTypeId = $criteria['blood_type_id'] ?? null;

        if ($reference === '' && ($surname === '' || $firstName === '')) {
            return new Collection;
        }

        return TransfusionRequest::query()
            ->raisedBy($hospitalId)
            ->whereIn('status', [
                BloodRequestStatus::Pending->value,
                BloodRequestStatus::Processing->value,
                BloodRequestStatus::Partial->value,
            ])
            ->when($excludeId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeId))
            ->where(function (Builder $query) use ($reference, $surname, $firstName, $bloodTypeId): void {
                if ($reference !== '') {
                    $query->orWhere('reference_number', $reference)
                        ->orWhereHas('facilityAllocations', fn (Builder $allocations): Builder => $allocations->where('reference_number', $reference));
                }

                if ($surname !== '' && $firstName !== '') {
                    $query->orWhere(function (Builder $byName) use ($surname, $firstName, $bloodTypeId): void {
                        $byName->whereRaw('LOWER(patient_surname) = ?', [$surname])
                            ->whereRaw('LOWER(patient_first_name) = ?', [$firstName])
                            ->where('request_date', '>=', now()->subDays(BloodRequestRepository::DUPLICATE_WINDOW_DAYS))
                            ->when($bloodTypeId !== null, fn (Builder $typed): Builder => $typed->where('blood_type_id', $bloodTypeId));
                    });
                }
            })
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('request_date')
            ->limit(10)
            ->get();
    }

    /**
     * Derive the next PTR-{hospital}-NNNN. Call under the hospital's facility lock.
     *
     * Parsed in PHP rather than taken from a lexicographic MAX, for the same
     * reason as the RQ sequence: as strings, 'PTR-4-100' sorts before
     * 'PTR-4-99'.
     */
    public function nextReference(int $facilityId): string
    {
        $prefix = "PTR-{$facilityId}-";
        $highest = 0;

        $existing = TransfusionRequest::query()
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
}
