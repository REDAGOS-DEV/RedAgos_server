<?php

namespace App\Service;

use App\Enums\AllocationStatus;
use App\Enums\LineClosureReason;
use App\Enums\RequestEventType;
use App\Models\BloodRequest;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Repository\InventoryRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
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
 */
class FulfillmentService
{
    /**
     * The note a weekly request's unsupplied remainder is closed with.
     */
    public const WEEKLY_SHORTFALL_NOTE = 'Not supplied in this weekly delivery.';

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
        private readonly RequestLineCloser $lineCloser
    ) {}

    /**
     * Dispatch the units held for a request.
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

        $result = DB::transaction(function () use ($user, $requestId, $facilityId, $allocationIds, $handedTo): array {
            $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            $from = $request->status;

            // The money gate. Under the present subsidy every statement is zero
            // and already settled, so this passes — but it is the real check,
            // and it starts refusing the moment a component carries a price.
            $this->billingService->assertClearsRelease($request);

            $holds = $request->allocations()
                ->where('status', AllocationStatus::Allocated)
                ->when($allocationIds !== null, fn ($query) => $query->whereIn('id', $allocationIds))
                ->lockForUpdate()
                ->get();

            if ($holds->isEmpty()) {
                throw $this->refuse(
                    409,
                    'nothing_to_release',
                    'This request has no reserved units waiting to be dispatched.'
                );
            }

            // A weekly request goes out in one delivery, because whatever it
            // leaves behind is closed as it goes. Holding some bags back would
            // close their line's remainder while they sat reserved.
            if ($request->isWeekly() && $request->allocations()
                ->where('status', AllocationStatus::Allocated)
                ->whereNotIn('id', $holds->pluck('id'))
                ->exists()) {
                throw $this->refuse(
                    409,
                    'weekly_release_all',
                    'A weekly request is dispatched in one delivery. Release every reserved unit, or return the ones you are not sending to stock first.'
                );
            }

            $unitIds = $holds->pluck('unit_id')->all();

            // The last gate before a unit leaves the building. A reserved unit
            // was released from quarantine on both tokens, so this should never
            // refuse — it is here so that nothing else, however it came to be
            // reserved, can send untested blood to a patient.
            $uncleared = $this->inventoryRepository->unitsLackingClearance($unitIds);

            if ($uncleared !== []) {
                throw $this->refuse(
                    409,
                    'unit_not_cleared',
                    'Unit '.implode(', ', $uncleared).' has not been cleared by testing and cannot be dispatched.'
                );
            }

            $issued = $this->inventoryRepository->markIssued($unitIds);

            if ($issued !== count($unitIds)) {
                throw new RuntimeException(
                    "release of request {$request->id}: issued {$issued} of ".count($unitIds).' units'
                );
            }

            RequestAllocation::query()->whereIn('id', $holds->pluck('id'))->update([
                'status' => AllocationStatus::Released->value,
                'released_at' => now(),
                'released_by' => $user->id,
            ]);

            $this->auditLogger->record($user, 'request.released', $request, [
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'units' => $unitIds,
            ]);

            foreach ($holds as $hold) {
                $this->auditLogger->record($user, 'allocation.issued', $hold->unit, [
                    'request_id' => $request->id,
                    'reference_number' => $request->reference_number,
                ]);
            }

            $this->resolver->settle($request);
            $this->transfusionResolver->settleParentOf($request);

            $handedTo = $handedTo !== null ? trim($handedTo) : null;

            $this->history->record(
                $request,
                RequestEventType::Released,
                $user,
                $from,
                $handedTo ? "Handed to {$handedTo}." : null,
                $unitIds,
                meta: $handedTo ? ['handed_to' => $handedTo] : [],
            );

            $closedShort = $request->isWeekly() ? $this->closeWeeklyShortfall($request, $user) : [];

            return ['request' => $request, 'units' => $unitIds, 'closed_short' => $closedShort];
        });

        $this->notifier->requester($result['request'], 'released');

        return [
            'message' => count($result['units']).' unit(s) released for dispatch.',
            'released_units' => $result['units'],
            'status' => $result['request']->status->value,
            'status_label' => $result['request']->status->label(),
            'closed_short' => $result['closed_short'],
        ];
    }

    /**
     * Close whatever a weekly request's delivery did not supply, as unavailable.
     *
     * The centre supplies what it can; the next request day's order replaces
     * the rest rather than leaving it open beside it. Runs inside release()'s
     * transaction, on the request it has locked, so the delivery and the
     * closure are one decision in the history.
     *
     * @return array<int, array{request_item_id: int, component: string|null, quantity: int}>
     */
    private function closeWeeklyShortfall(BloodRequest $request, User $user): array
    {
        if ($request->isClosed()) {
            return [];
        }

        $closed = [];

        foreach ($this->resolver->freshFigures($request) as $itemId => $line) {
            $short = (int) ($line['allocatable'] ?? 0);

            if ($short < 1) {
                continue;
            }

            $this->lineCloser->close(
                $request,
                (int) $itemId,
                LineClosureReason::Unavailable,
                self::WEEKLY_SHORTFALL_NOTE,
                $user
            );

            $closed[] = [
                'request_item_id' => (int) $itemId,
                'component' => $request->items->firstWhere('id', $itemId)?->component?->name,
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
            'message' => 'Receipt confirmed.',
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'received_count' => (int) $lines->sum('received'),
            'fulfilled_quantity' => (int) $lines->sum('fulfilled'),
            'stocked_count' => $stocked,
        ];
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
