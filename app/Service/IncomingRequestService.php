<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Reading the incoming queue, and reviewing one request against live stock.
 *
 * Read-only. Everything that changes a request lives in
 * RequestAllocationService or FulfillmentService, so a reviewer can look at a
 * request as often as they like without holding anything.
 */
class IncomingRequestService
{
    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly AvailabilityRepository $availabilityRepository
    ) {}

    /**
     * List requests addressed to the caller's facility.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function queue(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->bloodRequestRepository
            ->paginateAddressedTo($this->requireFacilityId($user), $filters, $perPage)
            ->through(fn (BloodRequest $request): array => $this->format($request));
    }

    /**
     * Count the queue by the states the dashboard shows.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $facilityId = $this->requireFacilityId($user);

        $counts = BloodRequest::query()
            ->addressedTo($facilityId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        // Projected from the enum so every state appears, including the ones
        // this facility currently has none of.
        $totals = [];

        foreach (BloodRequestStatus::values() as $status) {
            $totals[$status] = (int) ($counts[$status] ?? 0);
        }

        $emergencies = BloodRequest::query()
            ->addressedTo($facilityId)
            ->where('urgency_level', UrgencyLevel::Emergency->value)
            ->whereIn('status', [BloodRequestStatus::Pending->value, BloodRequestStatus::Processing->value])
            ->count();

        $awaitingRelease = BloodRequest::query()
            ->addressedTo($facilityId)
            ->whereHas('allocations', fn ($query) => $query->where('status', 'allocated'))
            ->count();

        return [
            'totals' => $totals,
            'open_emergencies' => $emergencies,
            'awaiting_release' => $awaitingRelease,
            'as_of' => OperationalDay::today()->toIso8601String(),
        ];
    }

    /**
     * Show one incoming request beside the stock that could fill it.
     *
     * The availability figure is read at the moment of review and is advisory
     * in exactly the way a search result is: it is not a hold, and allocation
     * re-checks every unit under a lock before anything is reserved.
     *
     * @return array<string, mixed>
     */
    public function review(User $user, int $requestId): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = $this->bloodRequestRepository->findAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $available = $this->availabilityRepository->availableAt(
            $facilityId,
            (int) $request->blood_type_id,
            (int) $request->component_id,
            OperationalDay::todayAsDate()
        );

        $held = $request->allocations->filter(
            fn (RequestAllocation $allocation): bool => $allocation->status->claimsUnit()
        )->count();

        $outstanding = max(0, $request->quantity - $held);

        return [
            'request' => $this->format($request, withAllocations: true),
            'inventory' => [
                'available' => $available,
                'outstanding' => $outstanding,
                'can_fully_cover' => $available >= $outstanding,
                'can_cover_now' => min($available, $outstanding),
                'advisory' => true,
                'as_of' => OperationalDay::today()->toIso8601String(),
            ],
        ];
    }

    /**
     * Project a request for the fulfilling facility's screens.
     *
     * @return array<string, mixed>
     */
    private function format(BloodRequest $request, bool $withAllocations = false): array
    {
        $allocations = $request->relationLoaded('allocations') ? $request->allocations : collect();
        $claimed = $allocations->filter(fn (RequestAllocation $a): bool => $a->status->claimsUnit());

        $allocatedCount = $request->allocated_count ?? $claimed->count();
        $receivedCount = $request->received_count ?? $claimed->whereNotNull('received_at')->count();

        $projection = [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'requesting_facility' => $this->facilityStub($request->requestingFacility),
            'blood_type' => [
                'id' => $request->blood_type_id,
                'code' => $request->bloodType?->code,
            ],
            'component' => [
                'id' => $request->component_id,
                'name' => $request->component?->name,
            ],
            'quantity' => $request->quantity,
            'urgency_level' => $request->urgency_level->value,
            'urgency_label' => $request->urgency_level->label(),
            'is_emergency' => $request->urgency_level->isPrioritised(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'allocated_count' => $allocatedCount,
            'received_count' => $receivedCount,
            'outstanding_quantity' => max(0, $request->quantity - $allocatedCount),
            'rejection_reason' => $request->rejection_reason,
            'request_date' => $request->request_date?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'fulfilled_at' => $request->fulfilled_at?->toIso8601String(),
        ];

        if ($withAllocations) {
            $projection['allocations'] = $allocations
                ->map(fn (RequestAllocation $allocation): array => [
                    'id' => $allocation->id,
                    'unit_id' => $allocation->unit_id,
                    'status' => $allocation->status->value,
                    'status_label' => $allocation->status->label(),
                    'expiry_date' => $allocation->unit?->expiry_date?->toDateString(),
                    'storage_location' => $allocation->unit?->storage_location,
                    'allocated_at' => $allocation->allocated_at?->toIso8601String(),
                    'released_at' => $allocation->released_at?->toIso8601String(),
                    'received_at' => $allocation->received_at?->toIso8601String(),
                ])
                ->all();
        }

        return $projection;
    }

    /**
     * Project the minimum a client needs to name a facility.
     *
     * @return array<string, mixed>|null
     */
    private function facilityStub(?Facility $facility): ?array
    {
        return $facility ? [
            'id' => $facility->id,
            'name' => $facility->name,
            'address' => $facility->address,
        ] : null;
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
