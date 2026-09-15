<?php

namespace App\Service;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Notifications\BloodRequestDecided;
use App\Repository\BloodRequestRepository;
use App\Repository\InventoryRepository;
use App\Support\OperationalDay;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The fulfilling side: reviewing an incoming request and holding stock for it.
 *
 * Every write here happens inside one transaction that begins by locking the
 * request row and ends by reconciling how many unit rows actually moved. That
 * shape is the whole defence against the failure this module exists to prevent
 * — the same bag promised to two hospitals.
 *
 * Approval and allocation are deliberately one operation. The paper's
 * storyboard separates them, but approving without reserving leaves a window in
 * which another request takes the units, and a requester told "approved" who
 * then receives nothing is worse than one told "we can only cover three".
 */
class RequestAllocationService
{
    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly InventoryRepository $inventoryRepository,
        private readonly BillingService $billingService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Hold stock for a request, up to what was asked and what is on the shelf.
     *
     * @return array<string, mixed>
     */
    public function allocate(User $user, int $requestId, ?int $wanted = null): array
    {
        $facilityId = $this->requireFacilityId($user);

        $result = DB::transaction(function () use ($user, $requestId, $facilityId, $wanted): array {
            $request = $this->lockRequestForDecision($requestId, $facilityId);

            $alreadyHeld = $this->claimedCount($request);
            $outstanding = $request->quantity - $alreadyHeld;

            if ($outstanding < 1) {
                throw $this->refuse(
                    409,
                    'request_fully_allocated',
                    'Every unit this request asked for is already held.'
                );
            }

            // Never more than the request asked for, whatever the caller sent.
            $take = min($wanted ?? $outstanding, $outstanding);

            $units = $this->inventoryRepository->lockAvailableUnitsFefo(
                $facilityId,
                (int) $request->blood_type_id,
                (int) $request->component_id,
                $take,
                OperationalDay::todayAsDate()
            );

            if ($units->isEmpty()) {
                throw $this->refuse(
                    409,
                    'no_matching_stock',
                    'No issuable units of that blood type and component are available.'
                );
            }

            $this->reserve($units);
            $allocations = $this->recordHolds($request, $units, $user);

            $heldNow = $alreadyHeld + $units->count();

            $request->status = BloodRequestStatus::Processing;
            $request->reviewed_by = $user->id;
            $request->reviewed_at = now();
            $request->rejection_reason = null;
            $request->save();

            $request->loadMissing('component');
            $billing = $this->billingService->syncFor($request, $user, $heldNow);

            $this->auditLogger->record($user, 'request.allocated', $request, [
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'units' => $units->pluck('id')->all(),
                'held_total' => $heldNow,
                'requested' => $request->quantity,
            ]);

            foreach ($units as $unit) {
                $this->auditLogger->record($user, 'allocation.reserved', $unit, [
                    'request_id' => $request->id,
                    'reference_number' => $request->reference_number,
                ]);
            }

            return [
                'request' => $request,
                'allocations' => $allocations,
                'billing' => $billing,
                'held_total' => $heldNow,
                'short_by' => max(0, $request->quantity - $heldNow),
            ];
        });

        $this->notifyRequester($result['request'], 'allocated');

        return [
            'message' => $result['short_by'] > 0
                ? "Partially fulfilled. {$result['short_by']} unit(s) still outstanding."
                : 'Request fully allocated.',
            'held_total' => $result['held_total'],
            'short_by' => $result['short_by'],
            'allocated_units' => $result['allocations']->pluck('unit_id')->all(),
            'billing' => $this->billingService->format($result['billing']),
        ];
    }

    /**
     * Refuse a request, with a reason the requester will see.
     *
     * @return array<string, mixed>
     */
    public function reject(User $user, int $requestId, string $reason): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = DB::transaction(function () use ($user, $requestId, $facilityId, $reason): BloodRequest {
            $request = $this->lockRequestForDecision($requestId, $facilityId);

            if ($this->claimedCount($request) > 0) {
                throw $this->refuse(
                    409,
                    'request_has_holds',
                    'Release the units held for this request before rejecting it.'
                );
            }

            $request->status = BloodRequestStatus::Rejected;
            $request->rejection_reason = $reason;
            $request->reviewed_by = $user->id;
            $request->reviewed_at = now();
            $request->save();

            $this->auditLogger->record($user, 'request.rejected', $request, [
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'reason' => $reason,
            ]);

            return $request;
        });

        $this->notifyRequester($request, 'rejected');

        return [
            'message' => 'Blood request rejected.',
            'request_id' => $request->id,
            'status' => $request->status->value,
        ];
    }

    /**
     * Give up holds and return their units to stock.
     *
     * @param  array<int, int>|null  $allocationIds
     * @return array<string, mixed>
     */
    public function releaseHolds(User $user, int $requestId, ?array $allocationIds, string $reason): array
    {
        $facilityId = $this->requireFacilityId($user);

        $freed = DB::transaction(function () use ($user, $requestId, $facilityId, $allocationIds, $reason): int {
            $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            $holds = $request->allocations()
                ->where('status', AllocationStatus::Allocated)
                ->when($allocationIds !== null, fn ($query) => $query->whereIn('id', $allocationIds))
                ->lockForUpdate()
                ->get();

            if ($holds->isEmpty()) {
                throw $this->refuse(409, 'no_holds_to_release', 'This request has no reserved units to release.');
            }

            $unitIds = $holds->pluck('unit_id')->all();
            $returned = $this->inventoryRepository->markAvailable($unitIds);

            if ($returned !== count($unitIds)) {
                throw new RuntimeException(
                    "release of request {$request->id}: returned {$returned} of ".count($unitIds).' units'
                );
            }

            RequestAllocation::query()->whereIn('id', $holds->pluck('id'))->update([
                'status' => AllocationStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            // Back to pending when nothing is held any more: the request is
            // undecided again, and leaving it "processing" would hide it from
            // the queue that needs to act on it.
            if ($this->claimedCount($request->fresh()) === 0) {
                $request->status = BloodRequestStatus::Pending;
                $request->reviewed_by = null;
                $request->reviewed_at = null;
                $request->save();
            }

            $this->auditLogger->record($user, 'allocation.released_to_stock', $request, [
                'facility_id' => $facilityId,
                'units' => $unitIds,
                'reason' => $reason,
            ]);

            return count($unitIds);
        });

        return [
            'message' => "{$freed} unit(s) returned to available stock.",
            'units_returned' => $freed,
        ];
    }

    /**
     * Lock a request and confirm it is one this facility may still decide.
     */
    private function lockRequestForDecision(int $requestId, int $facilityId): BloodRequest
    {
        $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        if (! $request->status->acceptsAllocation()) {
            throw $this->refuse(
                409,
                'request_closed',
                "This request is {$request->status->label()} and can no longer be acted on."
            );
        }

        return $request;
    }

    /**
     * Flip locked units to reserved, aborting if any moved out from underneath.
     *
     * @param  Collection<int, BloodUnit>  $units
     */
    private function reserve(Collection $units): void
    {
        $unitIds = $units->pluck('id')->all();
        $reserved = $this->inventoryRepository->markReserved($unitIds);

        // The lock should make this impossible, which is exactly why it is
        // checked: if it ever fires, the lock is not doing what this module
        // believes it does, and the transaction must not commit on that belief.
        if ($reserved !== count($unitIds)) {
            throw new RuntimeException(
                'allocation reserved '.$reserved.' of '.count($unitIds).' locked units'
            );
        }
    }

    /**
     * Write one hold per unit.
     *
     * @param  Collection<int, BloodUnit>  $units
     * @return Collection<int, RequestAllocation>
     */
    private function recordHolds(BloodRequest $request, Collection $units, User $user): Collection
    {
        return $units->map(fn (BloodUnit $unit): RequestAllocation => RequestAllocation::query()->create([
            'request_id' => $request->id,
            'unit_id' => $unit->id,
            'allocated_at' => now(),
            'allocated_by' => $user->id,
            'status' => AllocationStatus::Allocated,
        ]));
    }

    /**
     * How many units currently lay claim to this request.
     */
    private function claimedCount(BloodRequest $request): int
    {
        return $request->allocations()->claiming()->count();
    }

    /**
     * Tell the requesting facility what was decided.
     *
     * Outside the transaction on purpose: a notification failure must not roll
     * back a committed allocation, and blood that is physically reserved should
     * stay reserved even if the mail queue is down.
     */
    private function notifyRequester(BloodRequest $request, string $outcome): void
    {
        $request->loadMissing('requester');

        $request->requester?->notify(new BloodRequestDecided($request, $outcome));
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
