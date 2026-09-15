<?php

namespace App\Service;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Models\BloodRequest;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Notifications\BloodRequestDecided;
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
 * chain of custody a formality and let a request close on stock nobody ever
 * physically received.
 */
class FulfillmentService
{
    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly InventoryRepository $inventoryRepository,
        private readonly BillingService $billingService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Dispatch the units held for a request.
     *
     * @param  array<int, int>|null  $allocationIds
     * @return array<string, mixed>
     */
    public function release(User $user, int $requestId, ?array $allocationIds = null): array
    {
        $facilityId = $this->requireFacilityId($user);

        $result = DB::transaction(function () use ($user, $requestId, $facilityId, $allocationIds): array {
            $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

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

            $unitIds = $holds->pluck('unit_id')->all();
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

            return ['request' => $request, 'units' => $unitIds];
        });

        $this->notifyRequester($result['request'], 'released');

        return [
            'message' => count($result['units']).' unit(s) released for dispatch.',
            'released_units' => $result['units'],
        ];
    }

    /**
     * Confirm, as the requesting facility, that dispatched units arrived.
     *
     * @param  array<int, int>|null  $allocationIds
     * @return array<string, mixed>
     */
    public function confirmReceipt(User $user, int $requestId, ?array $allocationIds = null): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = DB::transaction(function () use ($user, $requestId, $facilityId, $allocationIds): BloodRequest {
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

            $this->settleStatus($request);

            $this->auditLogger->record($user, 'request.receipt_confirmed', $request, [
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'units' => $awaiting->pluck('unit_id')->all(),
                'status' => $request->status->value,
            ]);

            return $request;
        });

        return [
            'message' => 'Receipt confirmed.',
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
        ];
    }

    /**
     * Move the request to whatever its received units now justify.
     *
     * Fulfilled only when every unit asked for has been confirmed received.
     * Anything less that has received something is Partial, which deliberately
     * stays open to further allocation — a request short-filled from one batch
     * can still be topped up as stock arrives, and closing it would force the
     * requester to raise a second request for the same patient need.
     */
    private function settleStatus(BloodRequest $request): void
    {
        $received = $request->allocations()->claiming()->whereNotNull('received_at')->count();

        if ($received >= $request->quantity) {
            $request->status = BloodRequestStatus::Fulfilled;
            $request->fulfilled_at = now();
        } elseif ($received > 0) {
            $request->status = BloodRequestStatus::Partial;
        }

        $request->save();
    }

    /**
     * Tell the requesting facility their units are on the way.
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
