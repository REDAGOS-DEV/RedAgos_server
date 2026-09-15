<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\Department;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Notifications\BloodRequestSubmitted;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The requester side of the workflow: raising, tracking and withdrawing requests.
 *
 * Everything here acts for the facility the authenticated user belongs to. The
 * requesting facility is never taken from request input, so a blood bank cannot
 * raise a request in another's name or read one it did not send.
 */
class BloodRequestService
{
    /**
     * How many times submission will retry a reference-number collision.
     *
     * Mirrors InventoryService::ID_ATTEMPTS: the facility row lock serialises
     * submissions from one blood bank, but the unique index is global, so a
     * collision remains possible in principle and is retried rather than
     * surfaced.
     */
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly AvailabilityRepository $availabilityRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Raise a request against a chosen facility.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submit(User $user, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);
        $target = $this->requireEligibleTarget((int) $payload['target_facility_id'], $facilityId);

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                $request = DB::transaction(
                    fn (): BloodRequest => $this->persist($user, $facilityId, $target, $payload)
                );

                // After the commit, never inside it: a notification failure
                // must not roll back a request the requester was told landed.
                $this->notifyTargetFacility($request);

                return [
                    'message' => 'Blood request submitted.',
                    'request' => $this->format($request),
                ];
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw $this->refuse(
            409,
            'reference_generation_failed',
            'Could not allocate a request reference. Please try again.'
        );
    }

    /**
     * List the requests the caller's facility has raised.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->bloodRequestRepository
            ->paginateRaisedBy($this->requireFacilityId($user), $filters, $perPage)
            ->through(fn (BloodRequest $request): array => $this->format($request));
    }

    /**
     * Show one of the caller facility's own requests, with its progress.
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $requestId): array
    {
        $facilityId = $this->requireFacilityId($user);
        $request = $this->bloodRequestRepository->findRaisedBy($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        return ['request' => $this->format($request, withAllocations: true)];
    }

    /**
     * Track one request by the reference number printed on its paperwork.
     *
     * @return array<string, mixed>
     */
    public function track(User $user, string $reference): array
    {
        $facilityId = $this->requireFacilityId($user);
        $request = $this->bloodRequestRepository->findByReferenceFor($reference, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'No request found for that reference number.');

        return ['request' => $this->format($request, withAllocations: true)];
    }

    /**
     * Withdraw a request the caller's facility no longer needs.
     *
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $requestId, ?string $reason = null): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = DB::transaction(function () use ($requestId, $facilityId, $user, $reason): BloodRequest {
            $request = $this->bloodRequestRepository->lockRaisedBy($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            // Only while nothing is held. Once a facility has reserved stock,
            // giving that stock back is their operation, not the requester's —
            // a unilateral cancel here would leave units reserved for a request
            // that no longer exists.
            if (! $request->status->isWithdrawable()) {
                throw $this->refuse(
                    409,
                    'request_not_withdrawable',
                    $request->status === BloodRequestStatus::Cancelled
                        ? 'This request has already been cancelled.'
                        : 'This request is already being processed. Ask the fulfilling facility to release it.'
                );
            }

            $request->status = BloodRequestStatus::Cancelled;
            $request->save();

            $this->auditLogger->record($user, 'request.cancelled', $request, array_filter([
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'reason' => $reason,
            ], fn ($value): bool => $value !== null));

            return $request;
        });

        return [
            'message' => 'Blood request cancelled.',
            'request' => $this->format($request),
        ];
    }

    /**
     * Write the request and its reference number under the facility lock.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persist(User $user, int $facilityId, Facility $target, array $payload): BloodRequest
    {
        $this->bloodRequestRepository->lockFacility($facilityId)
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        $request = BloodRequest::query()->create([
            'reference_number' => $this->nextReference($facilityId),
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'requested_by' => $user->id,
            'blood_type_id' => $payload['blood_type_id'],
            'component_id' => $payload['component_id'],
            'quantity' => $payload['quantity'],
            'urgency_level' => $payload['urgency_level'],
            'status' => BloodRequestStatus::Pending,
            'request_date' => now(),
        ]);

        $this->auditLogger->record($user, 'request.submitted', $request, [
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'reference_number' => $request->reference_number,
            'quantity' => $request->quantity,
            'urgency_level' => $request->urgency_level->value,
        ]);

        return $request->load(['bloodType', 'component', 'requestingFacility', 'targetFacility']);
    }

    /**
     * Derive the next reference number for a facility.
     *
     * The sequence is parsed in PHP rather than taken from a lexicographic MAX,
     * for the same reason InventoryService derives unit ids that way: as a
     * string, 'RQ-4-100' sorts before 'RQ-4-99', so the maximum stops being the
     * latest once a facility passes its ninety-ninth request.
     */
    private function nextReference(int $facilityId): string
    {
        $prefix = "RQ-{$facilityId}-";
        $highest = 0;

        foreach ($this->bloodRequestRepository->existingReferences($prefix) as $reference) {
            $suffix = substr($reference, strlen($prefix));

            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve the target facility, refusing anything a request may not be sent to.
     */
    private function requireEligibleTarget(int $targetFacilityId, int $requestingFacilityId): Facility
    {
        if ($targetFacilityId === $requestingFacilityId) {
            throw ValidationException::withMessages([
                'target_facility_id' => ['A facility cannot send a blood request to itself.'],
            ]);
        }

        $target = Facility::query()->with('facilityType')->find($targetFacilityId);

        if (! $target || ! $this->availabilityRepository->isEligibleTarget($target)) {
            throw ValidationException::withMessages([
                'target_facility_id' => ['That facility cannot receive blood requests.'],
            ]);
        }

        return $target;
    }

    /**
     * Project a request for the API.
     *
     * @return array<string, mixed>
     */
    private function format(BloodRequest $request, bool $withAllocations = false): array
    {
        $allocations = $request->relationLoaded('allocations') ? $request->allocations : collect();
        $claimed = $allocations->filter(fn (RequestAllocation $a): bool => $a->status->claimsUnit());

        // The SQL aggregate wins where the query provided one. A listing does
        // not load allocations, so counting the relation there would report
        // every request as uncovered; the relation is only the fallback for a
        // single request loaded with its allocations.
        $allocatedCount = $request->allocated_count ?? $claimed->count();
        $receivedCount = $request->received_count ?? $claimed->whereNotNull('received_at')->count();

        $projection = [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'requesting_facility' => $this->facilityStub($request->requestingFacility),
            'target_facility' => $this->facilityStub($request->targetFacility),
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
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            // Derived rather than stored: the request status says how far the
            // request has got, and these say how much of it is covered.
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
     * Notify the staff who will have to act on a newly submitted request.
     *
     * Addressed to the target facility's inventory department, which
     * docs/BLOOD-CENTER.md charters to receive and process incoming requests.
     * Supervisors are included because they hold every ability and may be the
     * only account staffing a small centre out of hours.
     */
    private function notifyTargetFacility(BloodRequest $request): void
    {
        $recipients = User::query()
            ->where('facility_id', $request->target_facility_id)
            ->where(function ($query): void {
                $query->where('department', Department::Inventory->value)
                    ->orWhere('is_supervisor', true);
            })
            ->get();

        Notification::send($recipients, new BloodRequestSubmitted($request));
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
     * Whether a query failure is a unique-index collision.
     *
     * Matched on SQLSTATE rather than a driver message: PostgreSQL reports
     * 23505, MySQL and sqlite report 23000.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
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
