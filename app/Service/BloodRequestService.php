<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\HospitalUnitStatus;
use App\Enums\LineClosureReason;
use App\Enums\RequestEventType;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use App\Support\RequestFormReferenceData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly AvailabilityRepository $availabilityRepository,
        private readonly BloodRequestProjector $projector,
        private readonly BloodRequestFormService $formService,
        private readonly AuditLogger $auditLogger,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly RequestLineCloser $lineCloser,
        private readonly TransfusionRequestResolver $transfusionResolver
    ) {}

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
     * The hospital's own-stock vocabulary is added here rather than there,
     * because the walk-in form has no use for it.
     *
     * @return array<string, mixed>
     */
    public function referenceData(User $user): array
    {
        $this->requireFacilityId($user);

        return [
            ...RequestFormReferenceData::build(),
            'inventory_statuses' => array_map(
                fn (HospitalUnitStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                HospitalUnitStatus::cases()
            ),
            'tag_statuses' => array_map(
                fn (UnitTagStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'description' => $status->description(),
                ],
                UnitTagStatus::cases()
            ),
            'untag_reasons' => array_map(
                fn (UntagReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
                UntagReason::cases()
            ),
        ];
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
     * Close the rest of one replenishment line the hospital no longer needs.
     *
     * The line keeps what was asked for; the remainder is recorded as not
     * needed. A patient's need is closed on the Patient Transfusion Request
     * instead, which stops asking every facility for it at once.
     *
     * @return array<string, mixed>
     */
    public function closeLine(User $user, int $requestId, int $itemId, ?string $note): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = DB::transaction(function () use ($user, $requestId, $itemId, $note, $facilityId): BloodRequest {
            $request = $this->bloodRequestRepository->lockRaisedBy($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            if ($request->transfusion_request_id !== null) {
                $reference = $request->transfusionRequest()->value('reference_number');

                throw $this->refuse(
                    409,
                    'close_on_transfusion_request',
                    "Close the remaining quantity on Patient Transfusion Request {$reference}."
                );
            }

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

            // A facility allocation withdrawn is one share of a patient's need
            // no longer asked of this centre; its units are unallocated again.
            $this->history->record(
                $request,
                $request->transfusion_request_id !== null ? RequestEventType::AllocationWithdrawn : RequestEventType::Cancelled,
                $user,
                $from,
                $reason
            );

            $this->transfusionResolver->settleParentOf($request);

            return $request;
        });

        return [
            'message' => 'Blood request cancelled.',
            'request' => $this->format($request),
        ];
    }

    /**
     * Write one replenishment request, its lines, audit row and history.
     *
     * The caller must already hold the requesting facility's row lock
     * (BloodRequestRepository::lockFacility), which is what serialises the
     * RQ sequence. A weekly request (WeeklyRequestService) is written here as
     * one request, whatever blood types it restocks: each line names its own,
     * and the request names one only when every line shares it.
     *
     * @param  array<int, array{blood_type_id: int, component_id: int, quantity: int, indication_code?: string|null, indication_other?: string|null}>  $items
     */
    public function writeReplenishment(
        User $user,
        int $facilityId,
        Facility $target,
        UrgencyLevel $urgency,
        array $items,
        ?int $weeklyRequestId = null
    ): BloodRequest {
        $purpose = RequestPurpose::Replenishment;
        $bloodTypeIds = array_values(array_unique(array_column($items, 'blood_type_id')));

        // No patient columns: a restock order has no patient, and storing a
        // name it should not carry would put patient data on a record that has
        // none.
        $request = BloodRequest::query()->create([
            'reference_number' => $this->bloodRequestRepository->nextReference($facilityId),
            'weekly_request_id' => $weeklyRequestId,
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'requested_by' => $user->id,
            'request_purpose' => $purpose,
            'request_source' => RequestSource::BloodBankPortal,
            'blood_type_id' => count($bloodTypeIds) === 1 ? $bloodTypeIds[0] : null,
            'urgency_level' => $urgency,
            'status' => BloodRequestStatus::Pending,
            'request_date' => now(),
        ]);

        $request->items()->createMany(array_map(fn (array $item): array => [
            'blood_type_id' => $item['blood_type_id'],
            'component_id' => $item['component_id'],
            'quantity' => $item['quantity'],
            'indication_code' => $item['indication_code'] ?? null,
            'indication_other' => $item['indication_other'] ?? null,
        ], $items));

        $request->load(['bloodType', 'items.bloodType', 'items.component', 'requestingFacility', 'targetFacility']);

        $this->auditLogger->record($user, 'request.submitted', $request, array_filter([
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'reference_number' => $request->reference_number,
            'request_purpose' => $purpose->value,
            'quantity' => $request->quantity,
            'components' => $request->items->pluck('component.name')->all(),
            'urgency_level' => $request->urgency_level->value,
            'weekly_request_id' => $weeklyRequestId,
        ], fn ($value): bool => $value !== null));

        $this->history->record($request, RequestEventType::Submitted, $user);

        return $request;
    }

    /**
     * Resolve the target facility, refusing anything a request may not be sent to.
     *
     * Public so that every way a hospital addresses a centre — a STAT restock,
     * a weekly request, a request schedule — applies the one eligibility rule.
     */
    public function eligibleTarget(int $targetFacilityId, int $requestingFacilityId): Facility
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
