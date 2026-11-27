<?php

namespace App\Repository;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestEventType;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every read and write of Patient Transfusion Requests, scoped to the hospital that raised them.
 *
 * A requirement belongs to one hospital blood bank. The centres asked for
 * shares of it work those shares — their facility allocations, through
 * BloodRequestRepository. Since 2026-11-19 every blood centre may also read
 * every hospital's requirements, whoever holds them (paginateForCentres()),
 * as a stub: who the patient is, what they need and which centre holds each
 * share (project owner).
 */
class TransfusionRequestRepository
{
    /**
     * What a blood centre's read of every hospital's requirements needs.
     *
     * @var array<int, string>
     */
    private const NETWORK_RELATIONS = [
        ...self::FIGURE_RELATIONS,
        'bloodType',
        'hospital',
        'items.component',
        'facilityAllocations.targetFacility',
    ];

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
        'requester:id,first_name,last_name,credentials',
        'recorder:id,first_name,last_name,credentials',
        'facilityAllocations.targetFacility',
    ];

    /**
     * What the full view of one requirement needs, including each allocation's own projection.
     *
     * @var array<int, string>
     */
    private const DETAIL_RELATIONS = [
        ...self::LIST_RELATIONS,
        'items.closer:id,first_name,last_name,credentials',
        'facilityAllocations.items.component',
        'facilityAllocations.allocations.unit',
        'facilityAllocations.bloodType',
        'facilityAllocations.requestingFacility',
        'facilityAllocations.requester:id,first_name,last_name,credentials',
        'facilityAllocations.recorder:id,first_name,last_name,credentials',
        'facilityAllocations.walkIn',
        'facilityAllocations.walkIn.verificationRecorder:id,first_name,last_name,credentials',
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
            ->tap(fn (Builder $query): Builder => $this->withStepTimes($query))
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * When each step of the hospital's list last happened (2026-11-26): units
     * tagged, units released, receipt confirmed. Read off the request's own
     * history as one subquery each, so a page of rows costs no extra queries.
     * TransfusionRequestProjector shows them as `step_times`.
     */
    private function withStepTimes(Builder $query): Builder
    {
        foreach (self::STEP_EVENTS as $alias => $event) {
            $query->withMax(
                ['events as '.$alias => fn (Builder $events): Builder => $events->where('event', $event->value)],
                'created_at'
            );
        }

        return $query;
    }

    /**
     * The history event behind each step time, keyed by the attribute it loads as.
     *
     * @var array<string, RequestEventType>
     */
    public const STEP_EVENTS = [
        'approved_step_at' => RequestEventType::Allocated,
        'released_step_at' => RequestEventType::Released,
        'received_step_at' => RequestEventType::ReceiptConfirmed,
    ];

    /**
     * Every hospital's requirements, for any blood centre to read (project owner, 2026-11-19).
     *
     * Open ones first — emergencies first among them — then newest first.
     * `scope` is `open` (the default: still pending, processing or partly
     * filled, and not closed) or `all`. The search matches the PTR reference,
     * the RQ reference of any of its shares, the patient's name and the
     * hospital's name, in any case.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TransfusionRequest>
     */
    public function paginateForCentres(array $filters, int $perPage): LengthAwarePaginator
    {
        $open = [
            BloodRequestStatus::Pending->value,
            BloodRequestStatus::Processing->value,
            BloodRequestStatus::Partial->value,
        ];
        $placeholders = implode(', ', array_fill(0, count($open), '?'));
        $isOpen = "(status IN ({$placeholders}) AND closed_at IS NULL)";

        return TransfusionRequest::query()
            ->when(
                ($filters['scope'] ?? 'open') === 'open',
                fn (Builder $query): Builder => $query->whereIn('status', $open)->whereNull('closed_at')
            )
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): Builder {
                $like = '%'.mb_strtolower(trim((string) $filters['search'])).'%';

                return $query->where(fn (Builder $inner): Builder => $inner
                    ->whereRaw('LOWER(reference_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(patient_surname) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(patient_first_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(patient_first_name || ' ' || patient_surname) LIKE ?", [$like])
                    ->orWhereHas('hospital', fn (Builder $hospital): Builder => $hospital->whereRaw('LOWER(name) LIKE ?', [$like]))
                    ->orWhereHas('facilityAllocations', fn (Builder $shares): Builder => $shares->whereRaw('LOWER(reference_number) LIKE ?', [$like])));
            })
            ->with(self::NETWORK_RELATIONS)
            ->orderByRaw("CASE WHEN {$isOpen} THEN 0 ELSE 1 END", $open)
            ->orderByRaw("CASE WHEN {$isOpen} AND urgency_level = ? THEN 0 ELSE 1 END", [...$open, UrgencyLevel::Emergency->value])
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Re-read one requirement as paginateForCentres() reads it.
     */
    public function findForCentres(int $id): ?TransfusionRequest
    {
        return TransfusionRequest::query()->whereKey($id)->with(self::NETWORK_RELATIONS)->first();
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
