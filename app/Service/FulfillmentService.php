<?php

namespace App\Service;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\CorrectionSubject;
use App\Enums\LineClosureReason;
use App\Enums\RequestEventType;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Repository\InventoryRepository;
use App\Support\CorrectionValues;
use App\Support\DocumentNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dispatch and receipt: getting held units out of the door and confirming arrival.
 *
 * Release and receipt are separate operations performed by different facilities,
 * and neither may assert the other. The releasing centre says a bag left; only
 * the receiving hospital can say it arrived. Collapsing the two would make the
 * chain of custody a formality.
 *
 * Fulfilment is counted at dispatch: releasing units is what moves a request to
 * Partially Fulfilled or Fulfilled, because fulfilment is what the centre was
 * able to provide. Receipt is still stamped per unit, by the hospital alone,
 * and reported beside it as "received x of y" — the chain of custody is kept
 * in full; it just no longer decides the request's status.
 *
 * A patient's request goes out with the units tagged for it (release). A
 * replenishment order is never tagged (project owner, 2026-11-07): its bags
 * stay in stock for anyone until the moment they leave, when they are picked
 * and recorded in one step (dispatchOrder).
 */
class FulfillmentService
{
    /**
     * The note a weekly request's unsupplied remainder is closed with.
     */
    public const WEEKLY_SHORTFALL_NOTE = 'Not supplied in this weekly delivery.';

    /**
     * The note a line's remainder beyond the centre's commitment is closed with.
     */
    public const UNCOMMITTED_NOTE = 'Beyond what the centre committed to supply.';

    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly InventoryRepository $inventoryRepository,
        private readonly BillingService $billingService,
        private readonly AuditLogger $auditLogger,
        private readonly RequestStatusResolver $resolver,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly TransfusionRequestResolver $transfusionResolver,
        private readonly HospitalInventoryService $hospitalInventoryService,
        private readonly RequestLineCloser $lineCloser,
        private readonly ChosenUnitsMatcher $chosenUnits
    ) {}

    /**
     * Dispatch the units tagged for a patient's request.
     *
     * $handedTo names who physically took the units — for a walk-in, the
     * watcher carrying them to the hospital. It goes on the request's history,
     * never on the audit log.
     *
     * @param  array<int, int>|null  $allocationIds
     * @return array<string, mixed>
     */
    public function release(User $user, int $requestId, ?array $allocationIds = null, ?string $handedTo = null): array
    {
        $facilityId = $this->requireFacilityId($user);

        // A subsidised request may issue a Statement of Account revision as it
        // goes (below), so the transaction retries on a document-number collision.
        $result = DocumentNumbering::transaction(function () use ($user, $requestId, $facilityId, $allocationIds, $handedTo): array {
            $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            if ($request->isReplenishment()) {
                throw $this->refuse(
                    409,
                    'replenishment_not_tagged',
                    'A replenishment order has no tagged units. Pick its units as you dispatch it.'
                );
            }

            $from = $request->status;

            // The money gate: a statement must exist, and be settled or have its
            // payment deferred. Under the present subsidy every statement is
            // zero and already settled, so this passes — but it is the real
            // check, and it starts refusing the moment a component carries a price.
            $this->billingService->assertClearsRelease($request);

            // Units tagged after the subsidy are the hospital's too: its
            // Statement of Account is brought up to what is going out.
            $this->billingService->restateSubsidisedStatement($request, $user);

            $holds = $request->allocations()
                ->where('status', AllocationStatus::Allocated)
                ->when($allocationIds !== null, fn ($query) => $query->whereIn('id', $allocationIds))
                ->lockForUpdate()
                ->get();

            if ($holds->isEmpty()) {
                throw $this->refuse(
                    409,
                    'nothing_to_release',
                    'This request has no tagged units waiting to be dispatched.'
                );
            }

            $unitIds = $holds->pluck('unit_id')->all();

            $this->assertCleared($unitIds);

            $issued = $this->inventoryRepository->markIssued($unitIds);

            if ($issued !== count($unitIds)) {
                throw new RuntimeException(
                    "release of request {$request->id}: issued {$issued} of ".count($unitIds).' units'
                );
            }

            $handedTo = $handedTo !== null ? trim($handedTo) : null;

            RequestAllocation::query()->whereIn('id', $holds->pluck('id'))->update([
                'status' => AllocationStatus::Released->value,
                'released_at' => now(),
                'released_by' => $user->id,
                // Kept on the allocation as well as on the history, so the
                // dispatch record can show — and a correction can fix — who
                // took the units. Never on the audit log.
                'handed_to' => $handedTo ?: null,
            ]);

            $this->recordDispatch($user, $request, $from, $holds->map->unit, $handedTo);

            return ['request' => $request, 'units' => $unitIds];
        });

        $this->notifier->requester($result['request'], 'released');

        return $this->dispatched($result['request'], $result['units'], []);
    }

    /**
     * Dispatch a confirmed replenishment order: pick its units, and they are gone.
     *
     * Nothing was held for the order, so the bags staff name here move straight
     * from available to issued, with a released allocation for each, and the
     * statement is raised or brought up to them in the same transaction. Picking
     * is judged under a lock, so a bag tagged for a patient meanwhile is refused
     * by name. The commitment caps the pick, line by line.
     *
     * A weekly order goes out in one delivery, so whatever it leaves behind
     * closes as it goes. Any other restock may follow with more of what was
     * committed; only what lay beyond the commitment closes once that is met.
     *
     * @param  array<int, string>  $unitIds
     * @return array<string, mixed>
     */
    public function dispatchOrder(User $user, int $requestId, array $unitIds, ?string $handedTo = null): array
    {
        $facilityId = $this->requireFacilityId($user);

        // The statement's journal row takes a document number, so the
        // transaction retries on a collision.
        $result = DocumentNumbering::transaction(function () use ($user, $requestId, $facilityId, $unitIds, $handedTo): array {
            $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            if (! $request->isReplenishment()) {
                throw $this->refuse(
                    409,
                    'not_replenishment',
                    "A patient's request goes out with the units tagged for it. Only a replenishment order's units are picked at dispatch."
                );
            }

            if ($request->isClosed()) {
                throw $this->refuse(
                    409,
                    'request_closed',
                    "This order is {$request->status->label()} and can no longer be dispatched."
                );
            }

            if (! $request->supplyPlan()->exists()) {
                throw $this->refuse(
                    409,
                    'supply_plan_required',
                    'Confirm this order first: what you will supply of each line, and when it is picked up or delivered.'
                );
            }

            $from = $request->status;
            $figures = $this->resolver->freshFigures($request);

            if ($figures->sum('allocatable') < 1) {
                throw $this->refuse(
                    409,
                    'nothing_to_dispatch',
                    'Every unit you committed to supply on this order has already been dispatched.'
                );
            }

            $chosen = $this->chosenUnits->match($request->items()->orderBy('id')->get(), $figures, $unitIds, $facilityId);

            $this->assertCleared($unitIds);

            $issued = $this->inventoryRepository->markIssuedFromStock($unitIds);

            if ($issued !== count($unitIds)) {
                throw new RuntimeException(
                    "dispatch of order {$request->id}: issued {$issued} of ".count($unitIds).' units'
                );
            }

            $handedTo = $handedTo !== null ? trim($handedTo) : null;
            $at = now();
            $units = collect();

            foreach ($chosen as $pick) {
                foreach ($pick['units'] as $unit) {
                    RequestAllocation::query()->create([
                        'request_id' => $request->id,
                        'request_item_id' => $pick['line']->id,
                        'unit_id' => $unit->id,
                        'allocated_at' => $at,
                        'allocated_by' => $user->id,
                        'status' => AllocationStatus::Released,
                        'released_at' => $at,
                        'released_by' => $user->id,
                        'handed_to' => $handedTo ?: null,
                    ]);

                    $units->push($unit);
                }
            }

            $request->reviewed_by ??= $user->id;
            $request->reviewed_at ??= $at;
            $request->save();

            // Every issuance has a statement behind it, raised here for an order
            // that held nothing before now.
            $request->load('items.component');
            $this->billingService->syncFor($request, $user);
            $this->billingService->assertClearsRelease($request);

            $this->recordDispatch($user, $request, $from, $units, $handedTo);

            return [
                'request' => $request,
                'units' => $units->pluck('id')->all(),
                'closed_short' => $this->closeOrderShortfall($request, $user),
            ];
        });

        $this->notifier->requester($result['request'], 'released');

        return $this->dispatched($result['request'], $result['units'], $result['closed_short']);
    }

    /**
     * The last gate before a unit leaves the building.
     *
     * A unit on the shelf was released from quarantine on both tokens, so this
     * should never refuse — it is here so that nothing else, however it came to
     * be picked, can send untested blood out.
     *
     * @param  array<int, string>  $unitIds
     */
    private function assertCleared(array $unitIds): void
    {
        $uncleared = $this->inventoryRepository->unitsLackingClearance($unitIds);

        if ($uncleared !== []) {
            throw $this->refuse(
                409,
                'unit_not_cleared',
                'Unit '.implode(', ', $uncleared).' has not been cleared by testing and cannot be dispatched.'
            );
        }
    }

    /**
     * What every dispatch writes, however its units were chosen.
     *
     * $handedTo goes on the request's history, never on the audit log.
     *
     * @param  Collection<int, BloodUnit>  $units
     */
    private function recordDispatch(User $user, BloodRequest $request, BloodRequestStatus $from, Collection $units, ?string $handedTo): void
    {
        $unitIds = $units->pluck('id')->all();

        $this->auditLogger->record($user, 'request.released', $request, [
            'facility_id' => $request->target_facility_id,
            'reference_number' => $request->reference_number,
            'units' => $unitIds,
        ]);

        foreach ($units as $unit) {
            $this->auditLogger->record($user, 'allocation.issued', $unit, [
                'request_id' => $request->id,
                'reference_number' => $request->reference_number,
            ]);
        }

        $this->resolver->settle($request);
        $this->transfusionResolver->settleParentOf($request);

        $this->history->record(
            $request,
            RequestEventType::Released,
            $user,
            $from,
            $handedTo ? "Handed to {$handedTo}." : null,
            $unitIds,
            meta: $handedTo ? ['handed_to' => $handedTo] : [],
        );
    }

    /**
     * The answer to a dispatch.
     *
     * @param  array<int, string>  $unitIds
     * @param  array<int, array<string, mixed>>  $closedShort
     * @return array<string, mixed>
     */
    private function dispatched(BloodRequest $request, array $unitIds, array $closedShort): array
    {
        return [
            'message' => count($unitIds).' unit(s) released for dispatch.',
            'released_units' => $unitIds,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'closed_short' => $closedShort,
        ];
    }

    /**
     * Close what a replenishment order's dispatch leaves nothing further to come for.
     *
     * On a weekly order, everything not sent: the centre supplies what it can,
     * and the next request day's order replaces the rest rather than leaving it
     * open beside it. On any other restock, what lay beyond the commitment once
     * the commitment is met; more of what was committed may still follow. Runs
     * inside dispatchOrder()'s transaction, on the request it has locked, so the
     * delivery and the closure are one decision in the history.
     *
     * @return array<int, array{request_item_id: int, blood_type: string|null, component: string|null, quantity: int}>
     */
    private function closeOrderShortfall(BloodRequest $request, User $user): array
    {
        if ($request->isClosed()) {
            return [];
        }

        $weekly = $request->isWeekly();
        $closed = [];

        foreach ($this->resolver->freshFigures($request) as $itemId => $line) {
            $short = (int) ($line['unclaimed'] ?? 0);

            if ($short < 1 || (! $weekly && ($line['allocatable'] ?? 0) > 0)) {
                continue;
            }

            $this->lineCloser->close(
                $request,
                (int) $itemId,
                LineClosureReason::Unavailable,
                $weekly ? self::WEEKLY_SHORTFALL_NOTE : self::UNCOMMITTED_NOTE,
                $user
            );

            $item = $request->items->firstWhere('id', $itemId);

            $closed[] = [
                'request_item_id' => (int) $itemId,
                'blood_type' => $item?->bloodType?->code,
                'component' => $item?->component?->name,
                'quantity' => $short,
            ];
        }

        return $closed;
    }

    /**
     * Confirm, as the requesting facility, that dispatched units arrived.
     *
     * Receipt is also what puts each bag into the hospital blood bank's own
     * stock (HospitalInventoryService::stockReceived). The centre's side of the
     * bag stays exactly as dispatch left it.
     *
     * @param  array<int, int>|null  $allocationIds
     * @return array<string, mixed>
     */
    public function confirmReceipt(User $user, int $requestId, ?array $allocationIds = null): array
    {
        $facilityId = $this->requireFacilityId($user);

        [$request, $stocked] = DB::transaction(function () use ($user, $requestId, $facilityId, $allocationIds): array {
            $request = $this->bloodRequestRepository->lockRaisedBy($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            $awaiting = $request->allocations()
                ->where('status', AllocationStatus::Released)
                ->whereNull('received_at')
                ->when($allocationIds !== null, fn ($query) => $query->whereIn('id', $allocationIds))
                ->lockForUpdate()
                ->get();

            if ($awaiting->isEmpty()) {
                throw $this->refuse(
                    409,
                    'nothing_to_confirm',
                    'This request has no dispatched units awaiting confirmation.'
                );
            }

            RequestAllocation::query()->whereIn('id', $awaiting->pluck('id'))->update([
                'received_at' => now(),
                'received_by' => $user->id,
            ]);

            // The bags are on the hospital's shelf now, so they enter its
            // custody in the same transaction that says they arrived. Inserts
            // only: the lock order below is unchanged.
            $stocked = $this->hospitalInventoryService->stockReceived($user, $facilityId, $awaiting);

            // Receipt does not move the status — dispatch already did — but
            // the request is settled anyway so the figures are read fresh.
            $from = $request->status;
            $this->resolver->settle($request);
            $this->transfusionResolver->settleParentOf($request);

            $this->auditLogger->record($user, 'request.receipt_confirmed', $request, [
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'units' => $awaiting->pluck('unit_id')->all(),
                'status' => $request->status->value,
            ]);

            $this->history->record(
                $request,
                RequestEventType::ReceiptConfirmed,
                $user,
                $from,
                null,
                $awaiting->pluck('unit_id')->all(),
            );

            return [$request, $stocked];
        });

        $lines = $this->resolver->figures($request);

        return [
            // In custody now; inventory staff stock them in (project owner, 2026-10-11).
            'message' => 'Receipt confirmed. The units now wait in Inventory for stock-in.',
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'received_count' => (int) $lines->sum('received'),
            'fulfilled_quantity' => (int) $lines->sum('fulfilled'),
            // The bags taken into custody, awaiting stock-in. The key keeps
            // its name for the screens that already read it.
            'stocked_count' => $stocked,
            'awaiting_stock_in' => $stocked,
        ];
    }

    /**
     * Apply an approved correction to one dispatched allocation's record.
     *
     * Only when the units left and who took them can change. What the
     * allocation is — its status, its unit, its line — and who received it are
     * never touched: receipt is the hospital's alone, and a correction must not
     * move a bag through the workflow.
     *
     * Must be called inside a transaction that already holds the request and
     * then the allocation, in that order, which is the order release() and
     * confirmReceipt() take them. CorrectionService does, so nothing here locks.
     *
     * The name goes on the request's history, as release() puts it there, and
     * never on the audit log: only the fields that changed are audited.
     *
     * @param  array<string, mixed>  $changes  Already validated and normalised.
     */
    public function correctDispatch(User $actor, BloodRequest $lockedRequest, RequestAllocation $lockedAllocation, array $changes): void
    {
        if ($lockedAllocation->status !== AllocationStatus::Released) {
            throw $this->refuse(409, 'not_dispatched', 'Only a unit that has been dispatched has a dispatch record to correct.');
        }

        // A correction carries the whole corrected payload, so what is saved
        // and recorded is the fields whose value actually differs, in
        // canonical form.
        $types = CorrectionSubject::Dispatch->fieldTypes();
        $current = CorrectionValues::normalizeAll([
            'released_at' => $lockedAllocation->released_at,
            'handed_to' => $lockedAllocation->handed_to,
        ], $types);

        $fields = [];

        if (array_key_exists('released_at', $changes) && $changes['released_at'] !== null
            && ! CorrectionValues::same($current['released_at'] ?? null, $changes['released_at'], $types['released_at'])) {
            $releasedAt = CarbonImmutable::parse((string) $changes['released_at'])->setTimezone(config('app.timezone'));

            // Judged again here, against the row as it stands now: the
            // hospital may have confirmed receipt since the request was filed.
            $earliest = $lockedAllocation->earliestDeparture();

            if (($earliest !== null && $releasedAt->lt($earliest))
                || ($lockedAllocation->received_at !== null && $releasedAt->gt($lockedAllocation->received_at))) {
                throw $this->refuse(409, 'dispatch_time_invalid', 'That release time is before the unit could have left, or after it was received.');
            }

            $lockedAllocation->released_at = $releasedAt;
            $fields[] = 'released_at';
        }

        if (array_key_exists('handed_to', $changes)
            && ! CorrectionValues::same($current['handed_to'] ?? null, $changes['handed_to'], $types['handed_to'])) {
            $lockedAllocation->handed_to = $changes['handed_to'];
            $fields[] = 'handed_to';
        }

        if ($fields === []) {
            return;
        }

        $lockedAllocation->save();

        $unitId = $lockedAllocation->unit_id;

        $this->history->record(
            $lockedRequest,
            RequestEventType::DispatchCorrected,
            $actor,
            $lockedRequest->status,
            "Dispatch record of unit {$unitId} corrected.",
            [$unitId],
            meta: ['fields' => $fields],
        );

        $this->auditLogger->record($actor, 'allocation.dispatch_corrected', $lockedAllocation->unit, [
            'request_id' => $lockedRequest->id,
            'reference_number' => $lockedRequest->reference_number,
            'fields' => $fields,
        ]);
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(
            404,
            'facility_missing',
            'This account is not linked to a facility.'
        );
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
