<?php

namespace App\Repository;

use App\Enums\AllocationStatus;
use App\Enums\BillingStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Support\OperationalDay;
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
        'items.bloodType',
        'items.component',
        'requestingFacility',
        'targetFacility',
        // Every hold, so each row's per-line fulfilment can be worked out in
        // PHP. A page of requests holds a handful of bags each; one query for
        // all of them is cheaper than an aggregate per figure.
        'allocations',
        'requester:id,first_name,last_name,credentials',
        'recorder:id,first_name,last_name,credentials',
        'walkIn',
        'walkIn.verificationRecorder:id,first_name,last_name,credentials',
        // The patient requirement a facility allocation is a share of, so the
        // centre sees "you are asked for 1 of the 5 the patient needs".
        'transfusionRequest:id,reference_number,status,closed_at',
        'transfusionRequest.items:id,transfusion_request_id,component_id,quantity',
        'transfusionRequest.items.component:id,name',
        // A weekly request goes out in one delivery; both sides are told so.
        'weeklyRequest:id,reference_number,request_day',
        // How the centre will meet a replenishment order, once it has planned it.
        'supplyPlan',
        'supplyPlan.planner:id,first_name,last_name,credentials',
        'supplyPlan.updater:id,first_name,last_name,credentials',
    ];

    /**
     * The relations a projection needs, nested under a parent's relation name.
     *
     * For a parent that projects its own requests — a weekly request lists one
     * per blood type — so it can eager-load them all in one pass.
     *
     * @return array<int, string>
     */
    public function projectionRelationsUnder(string $relation, bool $withUnits = false): array
    {
        $relations = $withUnits ? [...self::PROJECTION_RELATIONS, 'allocations.unit'] : self::PROJECTION_RELATIONS;

        return [$relation, ...array_map(fn (string $nested): string => "{$relation}.{$nested}", $relations)];
    }

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
        return $this->filtered($filters, 'targetFacility')
            ->raisedBy($facilityId)
            ->with(self::PROJECTION_RELATIONS)
            ->withCount($this->coverageCounts())
            ->orderByDesc('request_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * List requests addressed to one facility, in queue order (see inQueueOrder()).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, BloodRequest>
     */
    public function paginateAddressedTo(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->addressedToQuery($facilityId, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The same rows as paginateAddressedTo(), unpaged, for the queue's CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<BloodRequest>
     */
    public function addressedToQuery(int $facilityId, array $filters): Builder
    {
        return $this->inQueueOrder(
            $this->filtered($filters, 'requestingFacility')
                ->addressedTo($facilityId)
                ->with(self::PROJECTION_RELATIONS)
                ->withCount($this->coverageCounts())
        );
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
     * Load everything a projection needs onto requests already in hand.
     */
    public function loadForProjection(BloodRequest $request, bool $withUnits = false): BloodRequest
    {
        return $request->load($withUnits
            ? [...self::PROJECTION_RELATIONS, 'allocations.unit']
            : self::PROJECTION_RELATIONS);
    }

    /**
     * Derive the next reference number for a hospital.
     *
     * Shared by replenishment submissions and every facility allocation of a
     * Patient Transfusion Request, however it was keyed in, so every request a
     * hospital sends a centre sits in one RQ-{hospital}-NNNN sequence. Call
     * under lockFacility().
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
     * `$counterparty` is the relation naming the facility on the other side
     * of the exchange, whose name the search also matches: the hospital that
     * asked, for the centre's queue; the centre asked, for a hospital's list.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<BloodRequest>
     */
    private function filtered(array $filters, ?string $counterparty = null): Builder
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
            // The hospital lists its replenishment orders apart from the
            // allocations of its patients' requirements, which it reads through
            // the requirement instead.
            ->when(
                isset($filters['request_purpose']),
                fn (Builder $query): Builder => $query->where('request_purpose', $filters['request_purpose'])
            )
            // The component, and on a weekly request the blood type, live on
            // the request's lines, so filtering by either asks whether any
            // line names it.
            ->when(
                isset($filters['blood_type_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'items',
                    fn (Builder $items): Builder => $items->where('blood_type_id', $filters['blood_type_id'])
                )
            )
            ->when(
                isset($filters['component_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'items',
                    fn (Builder $items): Builder => $items->where('component_id', $filters['component_id'])
                )
            )
            // The reference, the patient's name, or the other facility's name,
            // in any case: the list's one search box (project owner,
            // 2026-10-19, "Search patient / hospital").
            ->when(
                filled($filters['search'] ?? null),
                fn (Builder $query): Builder => $this->applySearch($query, (string) $filters['search'], $counterparty)
            )
            // When it was asked for, by operational day. request_date is an
            // instant, so the bounds are the day's first and last instants.
            ->when(
                isset($filters['from']),
                fn (Builder $query): Builder => $query->where('request_date', '>=', OperationalDay::boundsFor($filters['from'])[0])
            )
            ->when(
                isset($filters['to']),
                fn (Builder $query): Builder => $query->where('request_date', '<=', OperationalDay::boundsFor($filters['to'])[1])
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
            // Paid (or otherwise cleared) for release, or still owing
            // (2026-10-31): Request Fulfillment holds the second, For Dispatch
            // the first. A request with no invoice yet is still owing.
            ->when(
                isset($filters['release_cleared']),
                fn (Builder $query): Builder => $filters['release_cleared']
                    ? $query->whereHas('billing', fn (Builder $billing): Builder => $billing->whereIn('status', self::releaseClearing()))
                    : $query->whereDoesntHave('billing', fn (Builder $billing): Builder => $billing->whereIn('status', self::releaseClearing()))
            )
            // Only requests still being worked: not closed, not finished.
            ->when(
                isset($filters['open']) && $filters['open'],
                fn (Builder $query): Builder => $query->open()
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

    /**
     * The invoice states that clear units for release.
     *
     * @return array<int, string>
     */
    private static function releaseClearing(): array
    {
        return array_values(array_map(
            fn (BillingStatus $status): string => $status->value,
            array_filter(BillingStatus::cases(), fn (BillingStatus $status): bool => $status->clearsRelease())
        ));
    }

    /**
     * Match a reference number, a patient's name, or the other facility's name.
     *
     * Lower-cased on both sides, so "st luke" finds "St Luke Blood Bank" on
     * Postgres as well as SQLite.
     *
     * @param  Builder<BloodRequest>  $query
     * @return Builder<BloodRequest>
     */
    private function applySearch(Builder $query, string $term, ?string $counterparty): Builder
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->whereRaw('LOWER(reference_number) LIKE ?', [$like])
            ->orWhereRaw('LOWER(patient_surname) LIKE ?', [$like])
            ->orWhereRaw('LOWER(patient_first_name) LIKE ?', [$like])
            ->orWhereRaw("LOWER(patient_first_name || ' ' || patient_surname) LIKE ?", [$like])
            ->when($counterparty !== null, fn (Builder $q): Builder => $q->orWhereHas(
                $counterparty,
                fn (Builder $facility): Builder => $facility->whereRaw('LOWER(name) LIKE ?', [$like])
            )));
    }

    /**
     * The centre's queue order: requests still being worked first, those
     * emergencies first and then the oldest; then the finished ones, newest
     * first (project owner, 2026-10-19).
     *
     * Before, every request was triaged alike, so a fulfilled emergency from
     * last month sat above today's routine requests.
     *
     * @param  Builder<BloodRequest>  $query
     * @return Builder<BloodRequest>
     */
    private function inQueueOrder(Builder $query): Builder
    {
        $open = array_values(array_map(
            fn (BloodRequestStatus $status): string => $status->value,
            array_filter(BloodRequestStatus::cases(), fn (BloodRequestStatus $status): bool => ! $status->isTerminal())
        ));
        $placeholders = implode(', ', array_fill(0, count($open), '?'));
        $isOpen = "(status IN ({$placeholders}) AND closed_at IS NULL)";

        return $query
            ->orderByRaw("CASE WHEN {$isOpen} THEN 0 ELSE 1 END", $open)
            ->orderByRaw("CASE WHEN {$isOpen} AND urgency_level = ? THEN 0 ELSE 1 END", [...$open, UrgencyLevel::Emergency->value])
            // The open ones oldest first. A finished request gets NULL here and
            // ties, which only ever happens among finished ones, so where a
            // database puts NULLs does not matter: the next key decides.
            ->orderByRaw("CASE WHEN {$isOpen} THEN request_date END ASC", $open)
            ->orderByDesc('request_date')
            ->orderByDesc('id');
    }
}
