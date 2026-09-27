<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\LineClosureReason;
use App\Enums\RequestEventType;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use App\Support\RequestFormReferenceData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
        private readonly BloodRequestProjector $projector,
        private readonly BloodRequestFormService $formService,
        private readonly AuditLogger $auditLogger,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly RequestLineCloser $lineCloser,
        private readonly FollowUpRequestService $followUps
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
                $this->notifier->targetFacility($request);

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
     * Serve everything the request form needs to be filled in.
     *
     * Built by RequestFormReferenceData, which the blood centre's walk-in form
     * reads too, so the two forms can never offer different clinical lists.
     *
     * @return array<string, mixed>
     */
    public function referenceData(User $user): array
    {
        $this->requireFacilityId($user);

        return RequestFormReferenceData::build();
    }

    /**
     * Show one of the caller facility's requests' history, oldest first.
     *
     * @return array<string, mixed>
     */
    public function history(User $user, int $requestId): array
    {
        $facilityId = $this->requireFacilityId($user);
        $request = $this->bloodRequestRepository->findRaisedBy($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        return [
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'events' => $this->history->timeline($request),
        ];
    }

    /**
     * Close the rest of one line the hospital no longer needs.
     *
     * The line keeps what was asked for; the remainder is recorded as not
     * needed, which — unlike a centre's "unavailable" — may not then be sourced
     * from another facility.
     *
     * @return array<string, mixed>
     */
    public function closeLine(User $user, int $requestId, int $itemId, ?string $note): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = DB::transaction(function () use ($user, $requestId, $itemId, $note, $facilityId): BloodRequest {
            $request = $this->bloodRequestRepository->lockRaisedBy($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            return $this->lineCloser->close($request, $itemId, LineClosureReason::NotNeeded, $note, $user);
        });

        return [
            'message' => 'The remaining quantity was closed as no longer needed.',
            'request_id' => $request->id,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'is_open' => ! $request->isClosed(),
        ];
    }

    /**
     * Find this hospital's active requests for a patient it is about to request for.
     *
     * The warning a hospital sees before raising a second request for the same
     * patient — including one a blood centre recorded on its behalf when the
     * watcher went there first.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function patientMatches(User $user, array $criteria): array
    {
        $facilityId = $this->requireFacilityId($user);

        $matches = $this->bloodRequestRepository->patientMatches($facilityId, $criteria);

        return [
            'matches' => $matches->map(fn (BloodRequest $request): array => [
                'id' => $request->id,
                'reference_number' => $request->reference_number,
                'facility' => $request->targetFacility ? [
                    'id' => $request->targetFacility->id,
                    'name' => $request->targetFacility->name,
                ] : null,
                'request_source' => $request->request_source->value,
                'source_label' => $request->request_source->label(),
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'is_open' => ! $request->isClosed(),
                'request_date' => $request->request_date?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Render one of the caller facility's own requests as the DOH request form.
     *
     * Resolved through the same facility scope as show(), so a request raised
     * by another blood bank is a 404 here exactly as it is there. The document
     * is built by BloodRequestFormService, which the fulfilling side calls too
     * — both portals print the same sheet.
     */
    public function form(User $user, int $requestId): Response
    {
        $facilityId = $this->requireFacilityId($user);
        $request = $this->bloodRequestRepository->findRaisedBy($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        return $this->formService->download($request);
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

            $from = $request->status;

            $request->status = BloodRequestStatus::Cancelled;
            $request->save();

            $this->auditLogger->record($user, 'request.cancelled', $request, array_filter([
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'reason' => $reason,
            ], fn ($value): bool => $value !== null));

            $this->history->record($request, RequestEventType::Cancelled, $user, $from, $reason);

            // A withdrawn follow-up no longer carries its parent's remainder.
            $this->followUps->returnRemainderToParent($request, $user);

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

        $purpose = RequestPurpose::from($payload['request_purpose']);

        $request = BloodRequest::query()->create([
            'reference_number' => $this->bloodRequestRepository->nextReference($facilityId),
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'requested_by' => $user->id,
            'request_purpose' => $purpose,
            'request_source' => RequestSource::BloodBankPortal,
            // Patient identity is dropped rather than trusted when the request
            // is a restock. Validation already refuses to require it there, and
            // storing a name a replenishment order should not carry would put
            // patient data on a record that has no patient.
            'patient_surname' => $purpose->requiresPatient() ? $payload['patient_surname'] : null,
            'patient_first_name' => $purpose->requiresPatient() ? $payload['patient_first_name'] : null,
            'patient_middle_name' => $purpose->requiresPatient() ? ($payload['patient_middle_name'] ?? null) : null,
            'patient_age' => $purpose->requiresPatient() ? $payload['patient_age'] : null,
            'patient_sex' => $purpose->requiresPatient() ? $payload['patient_sex'] : null,
            'blood_type_id' => $payload['blood_type_id'],
            'urgency_level' => $payload['urgency_level'],
            'status' => BloodRequestStatus::Pending,
            'request_date' => now(),
        ]);

        $request->items()->createMany(array_map(fn (array $item): array => [
            'component_id' => $item['component_id'],
            'quantity' => $item['quantity'],
            'indication_code' => $item['indication_code'] ?? null,
            'indication_other' => $item['indication_other'] ?? null,
        ], $payload['items']));

        $request->load(['bloodType', 'items.component', 'requestingFacility', 'targetFacility']);

        $this->auditLogger->record($user, 'request.submitted', $request, [
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'reference_number' => $request->reference_number,
            'request_purpose' => $purpose->value,
            'quantity' => $request->quantity,
            'components' => $request->items->pluck('component.name')->all(),
            'urgency_level' => $request->urgency_level->value,
        ]);

        $this->history->record($request, RequestEventType::Submitted, $user);

        return $request;
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
