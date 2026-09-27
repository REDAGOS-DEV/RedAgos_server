<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestEventType;
use App\Enums\RequestSource;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\Facility;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sourcing the rest of a request from another blood service facility.
 *
 * A centre that can supply only part of a request supplies what it has. The
 * remainder can then be asked of a different facility through a follow-up: a
 * request of its own, addressed to that facility, whose lines point back at
 * the lines they carry. The parent keeps what it asked for; what was forwarded
 * is subtracted from what it still needs, so the same unit is never asked of
 * two facilities at once.
 *
 * The parent is locked for the whole of any follow-up write, and allocation
 * against the parent takes the same lock, so the forwarded quantity and the
 * parent's own holds can never both claim the same remainder.
 */
class FollowUpRequestService
{
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly AvailabilityRepository $availabilityRepository,
        private readonly BloodRequestProjector $projector,
        private readonly RequestStatusResolver $resolver,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Raise a follow-up from the Blood Bank Portal, for the hospital's own request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createFromPortal(User $user, int $parentId, array $payload): array
    {
        $facilityId = $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
        $target = $this->requireEligibleTarget((int) $payload['target_facility_id'], $facilityId);

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                $child = DB::transaction(function () use ($user, $facilityId, $parentId, $target, $payload): BloodRequest {
                    $this->bloodRequestRepository->lockFacility($facilityId)
                        ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

                    $parent = $this->bloodRequestRepository->lockRaisedBy($parentId, $facilityId)
                        ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

                    $lines = $this->assertForwardable($parent, $target->id, $payload['items']);

                    $child = BloodRequest::query()->create([
                        'reference_number' => $this->bloodRequestRepository->nextReference($facilityId),
                        'parent_request_id' => $parent->id,
                        'facility_id' => $facilityId,
                        'target_facility_id' => $target->id,
                        'requested_by' => $user->id,
                        'request_purpose' => $parent->request_purpose,
                        'request_source' => RequestSource::BloodBankPortal,
                        'patient_surname' => $parent->patient_surname,
                        'patient_first_name' => $parent->patient_first_name,
                        'patient_middle_name' => $parent->patient_middle_name,
                        'patient_age' => $parent->patient_age,
                        'patient_sex' => $parent->patient_sex,
                        'blood_type_id' => $parent->blood_type_id,
                        'urgency_level' => isset($payload['urgency_level'])
                            ? UrgencyLevel::from($payload['urgency_level'])
                            : $parent->urgency_level,
                        'status' => BloodRequestStatus::Pending,
                        'request_date' => now(),
                    ]);

                    $this->createLines($child, $lines);

                    $this->history->record(
                        $child,
                        RequestEventType::FollowUpCreated,
                        $user,
                        null,
                        "Carries the remaining quantity of {$parent->reference_number}.",
                        related: $parent,
                    );

                    $this->linkToParent($parent, $child, $user);

                    $this->auditLogger->record($user, 'request.follow_up_created', $child, [
                        'facility_id' => $facilityId,
                        'target_facility_id' => $target->id,
                        'reference_number' => $child->reference_number,
                        'parent_reference_number' => $parent->reference_number,
                        'quantity' => $lines->sum('quantity'),
                    ]);

                    return $child;
                });

                $this->notifier->targetFacility($child);

                return [
                    'message' => "Follow-up {$child->reference_number} sent to {$target->name}.",
                    'request' => $this->projector->project(
                        $this->bloodRequestRepository->loadForProjection($child, withUnits: true),
                        withAllocations: true
                    ),
                ];
            } catch (QueryException $exception) {
                if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                    throw $exception;
                }
            }
        }

        throw $this->refuse(409, 'reference_generation_failed', 'Could not allocate a request reference. Please try again.');
    }

    /**
     * Check that the given quantities may be carried from a locked parent to a target.
     *
     * Every requested line must belong to the parent, and none may ask for more
     * than that line can still forward: asked for, less what is held, released
     * or already forwarded, and nothing at all once the hospital said it no
     * longer needs the rest.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, array{parent: BloodRequestItem, quantity: int}>
     */
    public function assertForwardable(BloodRequest $parent, int $targetFacilityId, array $items): Collection
    {
        if (in_array($parent->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled, BloodRequestStatus::Fulfilled], true)) {
            throw $this->refuse(
                409,
                'parent_not_forwardable',
                "{$parent->reference_number} is {$parent->status->label()}; there is nothing left to source elsewhere."
            );
        }

        if ($targetFacilityId === (int) $parent->target_facility_id) {
            throw ValidationException::withMessages([
                'target_facility_id' => ['Choose a different facility from the one already handling '.$parent->reference_number.'.'],
            ]);
        }

        $figures = $this->resolver->freshFigures($parent);
        $parent->loadMissing('items.component');

        $lines = collect();
        $forwarding = [];

        foreach (array_values($items) as $index => $item) {
            $parentItemId = (int) ($item['parent_item_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            /** @var BloodRequestItem|null $parentItem */
            $parentItem = $parent->items->firstWhere('id', $parentItemId);

            if ($parentItem === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.parent_item_id" => ["That component is not on {$parent->reference_number}."],
                ]);
            }

            if (isset($forwarding[$parentItemId])) {
                throw ValidationException::withMessages([
                    "items.{$index}.parent_item_id" => ['Each component can only be listed once on a request.'],
                ]);
            }

            $forwardable = (int) ($figures->get($parentItemId)['forwardable'] ?? 0);

            if ($quantity > $forwardable) {
                $name = $parentItem->component?->name ?? 'that component';

                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => [$forwardable > 0
                        ? "Only {$forwardable} unit(s) of {$name} can still be sourced elsewhere."
                        : "Nothing of {$name} on {$parent->reference_number} is left to source elsewhere."],
                ]);
            }

            $forwarding[$parentItemId] = $quantity;
            $lines->push(['parent' => $parentItem, 'quantity' => $quantity]);
        }

        if ($this->resolver->wouldEmpty($figures, forwarding: $forwarding)) {
            throw $this->refuse(
                409,
                'forward_would_empty',
                "Nothing has been supplied or reserved on {$parent->reference_number} yet, so its whole request "
                .'cannot be moved as a follow-up. Record a separate request instead.'
            );
        }

        return $lines;
    }

    /**
     * Write a follow-up's lines, copied from the parent lines they carry.
     *
     * The component and indication are the parent's, not retyped: the
     * physician certified the indication once, on the original request.
     *
     * @param  Collection<int, array{parent: BloodRequestItem, quantity: int}>  $lines
     */
    public function createLines(BloodRequest $child, Collection $lines): void
    {
        foreach ($lines as $line) {
            $child->items()->create([
                'parent_item_id' => $line['parent']->id,
                'component_id' => $line['parent']->component_id,
                'quantity' => $line['quantity'],
                'indication_code' => $line['parent']->indication_code,
                'indication_other' => $line['parent']->indication_other,
            ]);
        }

        $child->unsetRelation('items');
    }

    /**
     * Record on the parent that part of it now travels with a follow-up, and settle it.
     *
     * Called with the parent locked and the follow-up's lines written.
     */
    public function linkToParent(BloodRequest $parent, BloodRequest $child, User $user): void
    {
        $from = $parent->status;
        $this->resolver->settle($parent);

        $child->loadMissing('targetFacility');

        $this->history->record(
            $parent,
            RequestEventType::RemainderForwarded,
            $user,
            $from,
            'Remaining quantity forwarded to '.($child->targetFacility?->name ?? 'another facility')
                ." as {$child->reference_number}.",
            related: $child,
        );
    }

    /**
     * Re-settle a follow-up's parent once the follow-up stops carrying its remainder.
     *
     * A refused or withdrawn follow-up covers nothing, so the quantity it was
     * carrying is outstanding on the parent again and a closed parent reopens.
     *
     * Called with the follow-up already locked. The parent is locked after it,
     * the only order in which the two are ever taken together — a follow-up is
     * created under its parent's lock, but the new row is not locked then — so
     * the two paths cannot wait on each other.
     */
    public function returnRemainderToParent(BloodRequest $followUp, ?User $user): void
    {
        $parent = $this->bloodRequestRepository->lockParentOf($followUp);

        if ($parent === null) {
            return;
        }

        $from = $parent->status;
        $this->resolver->settle($parent);

        $this->history->record(
            $parent,
            RequestEventType::FollowUpWithdrawn,
            $user,
            $from,
            "Follow-up {$followUp->reference_number} was {$followUp->status->label()}; its quantity is outstanding here again.",
            related: $followUp,
        );
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
