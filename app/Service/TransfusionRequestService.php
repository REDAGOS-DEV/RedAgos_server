<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\LineClosureReason;
use App\Enums\RequestEventType;
use App\Enums\RequestSource;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Repository\TransfusionRequestRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The hospital's side of a Patient Transfusion Request.
 *
 * The hospital blood bank records what its patient needs, checks the network,
 * and asks one or more centres for shares of it — each share a facility
 * allocation the centre approves or rejects on its own. From here it follows
 * what came of those asks, asks more centres for whatever is still
 * unallocated, withdraws an ask a centre has not answered, closes what the
 * patient no longer needs, or cancels the lot before anything is supplied.
 *
 * Everything acts for the hospital the authenticated user belongs to; the
 * requesting hospital is never taken from input.
 *
 * Locks are always taken in one order — the hospital's facility row (for the
 * reference sequences), then any allocations, then the requirement — which is
 * the order every allocation write already takes them in, so no two paths can
 * ever wait on each other.
 */
class TransfusionRequestService
{
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly TransfusionRequestRepository $repository,
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly TransfusionRequestResolver $resolver,
        private readonly RequestStatusResolver $allocationResolver,
        private readonly TransfusionRequestProjector $projector,
        private readonly TransfusionAllocationWriter $writer,
        private readonly SourcingPlanner $planner,
        private readonly RequestLineCloser $lineCloser,
        private readonly BloodRequestService $bloodRequestService,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Record a patient's requirement and ask the chosen centres for their shares.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                [$request, $allocations] = DB::transaction(
                    fn (): array => $this->persist($user, $facilityId, $payload)
                );

                // After the commit, never inside it.
                foreach ($allocations as $allocation) {
                    $this->notifier->targetFacility($allocation);
                }

                return [
                    'message' => "Patient Transfusion Request {$request->reference_number} sent to "
                        .count($allocations).' facilit'.(count($allocations) === 1 ? 'y' : 'ies').'.',
                    'request' => $this->projectFresh($request->id, $facilityId),
                ];
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw $this->refuse(409, 'reference_generation_failed', 'Could not allocate a request reference. Please try again.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repository
            ->paginateRaisedBy($this->requireFacilityId($user), $filters, $perPage)
            ->through(fn (TransfusionRequest $request): array => $this->projector->project($request, withAllocations: false));
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        return ['request' => $this->projectFresh($id, $this->requireFacilityId($user))];
    }

    /**
     * Track a requirement by its PTR reference or by the RQ reference of one of its allocations.
     *
     * @return array<string, mixed>
     */
    public function track(User $user, string $reference): array
    {
        $request = $this->repository->findByReferenceFor($reference, $this->requireFacilityId($user))
            ?? throw $this->refuse(404, 'request_not_found', 'No Patient Transfusion Request found for that reference number.');

        return ['request' => $this->projector->project($request)];
    }

    /**
     * Suggest how to split a requirement not yet recorded.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function draftSourcing(User $user, array $payload): array
    {
        return $this->planner->plan(
            $this->requireFacilityId($user),
            (int) $payload['blood_type_id'],
            array_map(fn (array $line): array => [
                'component_id' => (int) $line['component_id'],
                'quantity' => (int) $line['quantity'],
            ], $payload['lines'])
        );
    }

    /**
     * Suggest where to ask for whatever of a requirement is still unallocated.
     *
     * @return array<string, mixed>
     */
    public function sourcing(User $user, int $id): array
    {
        $facilityId = $this->requireFacilityId($user);
        $request = $this->repository->findRaisedBy($id, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Patient Transfusion Request not found.');

        $lines = $this->resolver->figures($request)
            ->filter(fn (array $line): bool => $line['unallocated'] > 0)
            ->map(fn (array $line): array => [
                'component_id' => $line['component_id'],
                'quantity' => $line['unallocated'],
                'transfusion_request_item_id' => $line['transfusion_request_item_id'],
            ])
            ->values()
            ->all();

        return $this->planner->plan($facilityId, (int) $request->blood_type_id, $lines);
    }

    /**
     * Ask more centres for whatever is still unallocated.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function addAllocations(User $user, int $id, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                $allocations = DB::transaction(function () use ($user, $id, $facilityId, $payload): array {
                    $this->bloodRequestRepository->lockFacility($facilityId)
                        ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

                    $request = $this->repository->lockRaisedBy($id, $facilityId)
                        ?? throw $this->refuse(404, 'request_not_found', 'Patient Transfusion Request not found.');

                    if ($request->isClosed()) {
                        throw $this->refuse(
                            409,
                            'request_closed',
                            "{$request->reference_number} is {$request->status->label()} and can no longer be allocated."
                        );
                    }

                    // Figures first: they reload the items, and the component
                    // names are then added to those.
                    $figures = $this->resolver->freshFigures($request);
                    $request->loadMissing('items.component');
                    $shares = $this->writer->resolveShares($request, $payload['allocations'], $figures);

                    $from = $request->status;
                    $allocations = array_map(
                        fn (array $share): BloodRequest => $this->writer->create(
                            $request,
                            $share['facility'],
                            $share['lines'],
                            $user,
                            RequestSource::BloodBankPortal
                        ),
                        $shares
                    );

                    $this->resolver->settle($request);

                    $names = implode(', ', array_map(fn (array $share): string => $share['facility']->name, $shares));

                    $this->history->recordForRequirement(
                        $request,
                        RequestEventType::AllocationsAdded,
                        $user,
                        $from,
                        "Asked {$names} for the remaining units.",
                        ['allocations' => array_map(fn (BloodRequest $allocation): string => $allocation->reference_number, $allocations)],
                    );

                    $this->auditLogger->record($user, 'request.transfusion_allocations_added', $request, [
                        'facility_id' => $facilityId,
                        'reference_number' => $request->reference_number,
                        'allocations' => array_map(fn (BloodRequest $allocation): string => $allocation->reference_number, $allocations),
                    ]);

                    return $allocations;
                });

                foreach ($allocations as $allocation) {
                    $this->notifier->targetFacility($allocation);
                }

                return [
                    'message' => count($allocations).' more facilit'.(count($allocations) === 1 ? 'y' : 'ies').' asked.',
                    'request' => $this->projectFresh($id, $facilityId),
                ];
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw $this->refuse(409, 'reference_generation_failed', 'Could not allocate a request reference. Please try again.');
    }

    /**
     * Withdraw an allocation a centre has not acted on yet.
     *
     * @return array<string, mixed>
     */
    public function withdrawAllocation(User $user, int $id, int $allocationId, ?string $reason): array
    {
        $facilityId = $this->requireFacilityId($user);

        $belongs = BloodRequest::query()
            ->raisedBy($facilityId)
            ->whereKey($allocationId)
            ->where('transfusion_request_id', $id)
            ->exists();

        if (! $belongs) {
            throw $this->refuse(404, 'request_not_found', 'That allocation is not part of this request.');
        }

        $this->bloodRequestService->cancel($user, $allocationId, $reason);

        return [
            'message' => 'Allocation withdrawn. Its units are unallocated again.',
            'request' => $this->projectFresh($id, $facilityId),
        ];
    }

    /**
     * Close the rest of one requirement line the patient no longer needs.
     *
     * Nothing already approved is touched — those units are still the
     * patient's. What stops is asking: pending shares of this component are
     * closed on every allocation, and an allocation left asking for nothing is
     * withdrawn.
     *
     * @return array<string, mixed>
     */
    public function closeLine(User $user, int $id, int $itemId, ?string $note): array
    {
        $facilityId = $this->requireFacilityId($user);
        $this->requireOwn($id, $facilityId);

        DB::transaction(function () use ($user, $id, $itemId, $note, $facilityId): void {
            [$allocations, $request] = $this->lockWhole($id, $facilityId);

            if ($request->isClosed()) {
                throw $this->refuse(409, 'request_closed', "{$request->reference_number} is {$request->status->label()} and can no longer be changed.");
            }

            $item = $request->items()->with('component')->whereKey($itemId)->first()
                ?? throw $this->refuse(404, 'request_item_not_found', 'That component is not on this request.');

            if ($item->closed_at !== null) {
                throw $this->refuse(409, 'line_already_closed', 'The rest of this component has already been closed.');
            }

            $figures = $this->resolver->freshFigures($request);
            $line = $figures->get($item->id);
            $closing = (int) ($line['unallocated'] ?? 0) + (int) ($line['awaiting'] ?? 0);

            if ($closing < 1) {
                throw $this->refuse(409, 'nothing_to_close', "Every unit of {$item->component?->name} is already approved.");
            }

            if ($this->resolver->wouldEmpty($figures, [$item->id])) {
                throw $this->refuse(
                    409,
                    'close_would_empty',
                    'Nothing has been approved for this patient yet, so closing this would leave the request with nothing. '
                    .'Cancel the request instead.'
                );
            }

            foreach ($allocations as $allocation) {
                $this->stopAsking($allocation, $item->id, $user, $note);
            }

            $item->closed_at = now();
            $item->closed_by = $user->id;
            $item->closure_note = $note;
            $item->save();

            $from = $request->status;
            $this->resolver->settle($request);

            $this->history->recordForRequirement(
                $request,
                RequestEventType::RequirementClosed,
                $user,
                $from,
                trim(($item->component?->name ?? 'Component').' — no longer needed'.($note ? ": {$note}" : '.')),
                ['component' => $item->component?->name, 'closed_quantity' => $closing],
            );

            $this->auditLogger->record($user, 'request.transfusion_line_closed', $request, [
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'transfusion_request_item_id' => $item->id,
                'closed_quantity' => $closing,
                'status' => $request->status->value,
            ]);
        });

        return [
            'message' => 'The remaining quantity was closed as no longer needed.',
            'request' => $this->projectFresh($id, $facilityId),
        ];
    }

    /**
     * Cancel a requirement nothing has been supplied for.
     *
     * Once any centre holds or has released a unit for the patient, those
     * units are real and cancelling would strand them; the hospital closes the
     * remaining quantities instead.
     *
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $id, ?string $reason): array
    {
        $facilityId = $this->requireFacilityId($user);
        $this->requireOwn($id, $facilityId);

        DB::transaction(function () use ($user, $id, $reason, $facilityId): void {
            [$allocations, $request] = $this->lockWhole($id, $facilityId);

            if ($request->status === BloodRequestStatus::Cancelled) {
                throw $this->refuse(409, 'request_not_withdrawable', 'This request has already been cancelled.');
            }

            if ($request->isClosed()) {
                throw $this->refuse(409, 'request_closed', "{$request->reference_number} is {$request->status->label()} and can no longer be cancelled.");
            }

            foreach ($allocations as $allocation) {
                if ($allocation->allocations()->claiming()->exists()) {
                    throw $this->refuse(
                        409,
                        'request_has_supply',
                        'Units have already been reserved or released for this patient. Close the remaining quantities instead.'
                    );
                }
            }

            foreach ($allocations as $allocation) {
                // Refused, withdrawn and closed allocations hold nothing and
                // ask for nothing; they are left as they ended.
                if ($allocation->isClosed()) {
                    continue;
                }

                $this->withdraw($allocation, $user, 'Withdrawn with the request'.($reason ? ": {$reason}" : '.'));
            }

            $from = $request->status;
            $request->status = BloodRequestStatus::Cancelled;
            $request->cancelled_at = now();
            $request->cancelled_by = $user->id;
            $request->cancellation_reason = $reason;
            $request->save();

            $this->history->recordForRequirement($request, RequestEventType::TransfusionCancelled, $user, $from, $reason);

            $this->auditLogger->record($user, 'request.transfusion_cancelled', $request, array_filter([
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'reason' => $reason,
            ], fn ($value): bool => $value !== null));
        });

        return [
            'message' => 'Patient Transfusion Request cancelled.',
            'request' => $this->projectFresh($id, $facilityId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(User $user, int $id): array
    {
        $request = $this->repository->findRaisedBy($id, $this->requireFacilityId($user))
            ?? throw $this->refuse(404, 'request_not_found', 'Patient Transfusion Request not found.');

        return [
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'events' => $this->history->timelineForRequirement($request),
        ];
    }

    /**
     * This hospital's active requirements for a patient, before it records another.
     *
     * Includes a requirement a blood centre recorded here after a walk-in.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function patientMatches(User $user, array $criteria): array
    {
        $matches = $this->repository->patientMatches($this->requireFacilityId($user), $criteria);

        return [
            'matches' => $matches->map(function (TransfusionRequest $request): array {
                $projection = $this->projector->project($request, withAllocations: false);

                return [
                    'id' => $request->id,
                    'reference_number' => $request->reference_number,
                    'request_source' => $projection['request_source'],
                    'source_label' => $projection['source_label'],
                    'status' => $projection['status'],
                    'status_label' => $projection['status_label'],
                    'is_open' => $projection['is_open'],
                    'request_date' => $projection['request_date'],
                    'facilities' => $projection['facilities'],
                    'totals' => $projection['totals'],
                ];
            })->values()->all(),
        ];
    }

    /**
     * Write the requirement, its lines and its first allocations under the hospital's lock.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: TransfusionRequest, 1: array<int, BloodRequest>}
     */
    private function persist(User $user, int $facilityId, array $payload): array
    {
        $this->bloodRequestRepository->lockFacility($facilityId)
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        $request = TransfusionRequest::query()->create([
            'reference_number' => $this->repository->nextReference($facilityId),
            'facility_id' => $facilityId,
            'requested_by' => $user->id,
            'request_source' => RequestSource::BloodBankPortal,
            'patient_surname' => $payload['patient_surname'],
            'patient_first_name' => $payload['patient_first_name'],
            'patient_middle_name' => $payload['patient_middle_name'] ?? null,
            'patient_age' => $payload['patient_age'],
            'patient_sex' => $payload['patient_sex'],
            'blood_type_id' => $payload['blood_type_id'],
            'urgency_level' => UrgencyLevel::from($payload['urgency_level']),
            // The form cannot be submitted without staff confirming their own
            // stock is short, so this is when they did.
            'internal_stock_checked_at' => now(),
            'request_date' => now(),
        ]);

        $request->items()->createMany(array_map(fn (array $line): array => [
            'component_id' => $line['component_id'],
            'quantity' => $line['quantity'],
            'indication_code' => $line['indication_code'] ?? null,
            'indication_other' => $line['indication_other'] ?? null,
        ], $payload['lines']));

        $figures = $this->resolver->freshFigures($request);
        $request->loadMissing('items.component');
        $shares = $this->writer->resolveShares($request, $payload['allocations'], $figures);

        $this->history->recordForRequirement(
            $request,
            RequestEventType::TransfusionCreated,
            $user,
            null,
            'Hospital stock confirmed insufficient; '.count($shares).' facilit'.(count($shares) === 1 ? 'y' : 'ies').' asked.',
        );

        $allocations = array_map(
            fn (array $share): BloodRequest => $this->writer->create(
                $request,
                $share['facility'],
                $share['lines'],
                $user,
                RequestSource::BloodBankPortal
            ),
            $shares
        );

        $this->resolver->settle($request);

        $this->auditLogger->record($user, 'request.transfusion_created', $request, [
            'facility_id' => $facilityId,
            'reference_number' => $request->reference_number,
            'quantity' => $request->quantity,
            'components' => $request->items->pluck('component.name')->all(),
            'allocations' => array_map(fn (BloodRequest $allocation): string => $allocation->reference_number, $allocations),
            'urgency_level' => $request->urgency_level->value,
        ]);

        return [$request, $allocations];
    }

    /**
     * Stop asking one locked allocation for a component the patient no longer needs.
     */
    private function stopAsking(BloodRequest $allocation, int $requirementItemId, User $user, ?string $note): void
    {
        if ($allocation->isClosed()) {
            return;
        }

        $lines = $this->allocationResolver->freshFigures($allocation);
        $affected = $lines->filter(
            fn (array $line): bool => $line['transfusion_request_item_id'] === $requirementItemId && $line['allocatable'] > 0
        );

        if ($affected->isEmpty()) {
            return;
        }

        $holdsAny = $lines->sum('reserved') + $lines->sum('fulfilled') > 0;
        $asksForMore = $lines->contains(
            fn (array $line): bool => $line['transfusion_request_item_id'] !== $requirementItemId && $line['allocatable'] > 0
        );

        // An allocation that holds nothing and would be left asking for
        // nothing is withdrawn outright rather than left open and empty.
        if (! $holdsAny && ! $asksForMore) {
            $this->withdraw($allocation, $user, 'Withdrawn: the patient no longer needs it'.($note ? " — {$note}" : '.'));

            return;
        }

        foreach ($affected as $line) {
            $this->lineCloser->close(
                $allocation,
                (int) $line['request_item_id'],
                LineClosureReason::NotNeeded,
                $note,
                $user
            );
        }
    }

    /**
     * Withdraw one locked allocation that holds nothing.
     */
    private function withdraw(BloodRequest $allocation, User $user, string $note): void
    {
        $from = $allocation->status;
        $allocation->status = BloodRequestStatus::Cancelled;
        $allocation->save();

        $this->history->record($allocation, RequestEventType::AllocationWithdrawn, $user, $from, $note);

        $this->auditLogger->record($user, 'request.cancelled', $allocation, [
            'facility_id' => $user->facility_id,
            'reference_number' => $allocation->reference_number,
            'transfusion_request_id' => $allocation->transfusion_request_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectFresh(int $id, int $facilityId): array
    {
        $request = $this->repository->findRaisedBy($id, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Patient Transfusion Request not found.');

        return $this->projector->project($request);
    }

    /**
     * Lock every allocation of a requirement, then the requirement itself.
     *
     * Allocations first, the order every centre write takes them in. Another
     * member of staff may add allocations between the two locks — adding takes
     * only the requirement's lock — and an allocation this call never locked
     * would be left asking under a requirement it is about to close or cancel.
     * So once the requirement is held, and nothing more can be added, the set
     * is read again; if it grew, this refuses rather than lock the newcomer in
     * the opposite order.
     *
     * @return array{0: Collection<int, BloodRequest>, 1: TransfusionRequest}
     */
    private function lockWhole(int $id, int $facilityId): array
    {
        $allocations = $this->repository->lockAllocationsOf($id);
        $request = $this->repository->lockRaisedBy($id, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Patient Transfusion Request not found.');

        $current = BloodRequest::query()
            ->where('transfusion_request_id', $id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($current !== $allocations->pluck('id')->all()) {
            throw $this->refuse(
                409,
                'request_changed',
                'Another facility was just asked for this patient. Refresh the request and try again.'
            );
        }

        return [$allocations, $request];
    }

    /**
     * Refuse, before any lock is taken, a requirement that is not this hospital's.
     */
    private function requireOwn(int $id, int $facilityId): void
    {
        if (! TransfusionRequest::query()->raisedBy($facilityId)->whereKey($id)->exists()) {
            throw $this->refuse(404, 'request_not_found', 'Patient Transfusion Request not found.');
        }
    }

    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
