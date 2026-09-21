<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

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
        private readonly AvailabilityRepository $availabilityRepository,
        private readonly BloodRequestProjector $projector,
        private readonly BloodRequestFormService $formService
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

        $claimed = $request->allocations->filter(
            fn (RequestAllocation $allocation): bool => $allocation->status->claimsUnit()
        );
        $heldPerItem = $claimed->groupBy('request_item_id');

        // Stock is counted per line, not per request: a form asking for packed
        // cells and platelets has two different shelves to answer it from, and
        // one figure covering both would say nothing useful about either.
        $lines = $request->items->map(function (BloodRequestItem $item) use ($facilityId, $request, $heldPerItem): array {
            $available = $this->availabilityRepository->availableAt(
                $facilityId,
                (int) $request->blood_type_id,
                (int) $item->component_id,
                OperationalDay::todayAsDate()
            );

            $outstanding = max(0, $item->quantity - ($heldPerItem->get($item->id)?->count() ?? 0));

            return [
                'request_item_id' => $item->id,
                'component' => [
                    'id' => $item->component_id,
                    'name' => $item->component?->name,
                ],
                'available' => $available,
                'outstanding' => $outstanding,
                'can_fully_cover' => $available >= $outstanding,
                'can_cover_now' => min($available, $outstanding),
            ];
        });

        $outstanding = (int) $lines->sum('outstanding');

        return [
            'request' => $this->format($request, withAllocations: true),
            'inventory' => [
                'lines' => $lines->all(),
                'outstanding' => $outstanding,
                'can_fully_cover' => $lines->every(fn (array $line): bool => $line['can_fully_cover']),
                'can_cover_now' => (int) $lines->sum('can_cover_now'),
                'advisory' => true,
                'as_of' => OperationalDay::today()->toIso8601String(),
            ],
        ];
    }

    /**
     * Render one incoming request as the DOH request form.
     *
     * Scoped to requests addressed to the caller's facility, so a centre can
     * print the paperwork for work it has actually been asked to do and
     * nothing else. The same service builds the hospital's copy, so the two
     * are the same document.
     */
    public function form(User $user, int $requestId): Response
    {
        $facilityId = $this->requireFacilityId($user);
        $request = $this->bloodRequestRepository->findAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        return $this->formService->download($request);
    }

    /**
     * Project a request for the fulfilling facility's screens.
     *
     * @return array<string, mixed>
     */
    private function format(BloodRequest $request, bool $withAllocations = false): array
    {
        return $this->projector->project($request, $withAllocations);
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
