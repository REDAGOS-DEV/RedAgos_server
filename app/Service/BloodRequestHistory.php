<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\LineFulfilmentStatus;
use App\Enums\RequestEventType;
use App\Enums\TransfusionLineStatus;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\BloodRequestItem;
use App\Models\TransfusionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes and reads a blood request's history.
 *
 * Every change to a request lands here as one event: who did it, from which
 * facility, the status it moved between, and a snapshot of every line at that
 * moment. The snapshot is what makes fulfilment auditable — "FFP 1 of 2
 * released, 1 remaining" is recorded as it stood, not reconstructed later from
 * allocations that may since have moved.
 *
 * An event on a facility allocation also names the Patient Transfusion Request
 * it belongs to, and the requirement has events of its own — created,
 * allocations added, remaining closed, cancelled — so the requirement's
 * timeline is every one of its allocations' histories and its own, in order.
 *
 * Not the audit log. audit_logs is the security trail and carries identifiers
 * only; this is what the two portals show as the request's timeline.
 */
class BloodRequestHistory
{
    /**
     * @var array<int, string>
     */
    private const TIMELINE_RELATIONS = [
        'actor:id,first_name,last_name',
        'actorFacility:id,name',
        'relatedRequest:id,reference_number,target_facility_id',
        'relatedRequest.targetFacility:id,name',
        'requestItem.component:id,name',
        'request:id,reference_number,target_facility_id',
        'request.targetFacility:id,name',
    ];

    public function __construct(
        private readonly RequestStatusResolver $resolver,
        private readonly TransfusionRequestResolver $requirementResolver
    ) {}

    /**
     * Record one event against a facility allocation or a plain request, as it stands now.
     *
     * Call after the change and after RequestStatusResolver::settle(), inside
     * the same transaction, so the snapshot and to_status describe the result.
     *
     * @param  array<int, string>  $unitIds
     * @param  array<string, mixed>  $meta
     */
    public function record(
        BloodRequest $request,
        RequestEventType $event,
        ?User $actor,
        ?BloodRequestStatus $from = null,
        ?string $note = null,
        array $unitIds = [],
        ?BloodRequest $related = null,
        ?BloodRequestItem $item = null,
        array $meta = [],
    ): BloodRequestEvent {
        $lines = $this->resolver->freshFigures($request);
        $request->loadMissing('items.component');
        $names = $request->items->pluck('component.name', 'id');

        return BloodRequestEvent::query()->create([
            'request_id' => $request->id,
            'transfusion_request_id' => $request->transfusion_request_id,
            'request_item_id' => $item?->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $request->status,
            'actor_id' => $actor?->id,
            'actor_facility_id' => $actor?->facility_id,
            'related_request_id' => $related?->id,
            'lines' => $lines->map(fn (array $line): array => [
                'request_item_id' => $line['request_item_id'],
                'component' => $names->get($line['request_item_id']),
                'requested' => $line['requested'],
                'reserved' => $line['reserved'],
                'fulfilled' => $line['fulfilled'],
                'received' => $line['received'],
                'remaining' => $line['remaining'],
                'status' => $line['status']->value,
            ])->values()->all(),
            'unit_ids' => $unitIds === [] ? null : array_values($unitIds),
            'meta' => $meta === [] ? null : $meta,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
        ]);
    }

    /**
     * Record one event against a patient's requirement as a whole, as it stands now.
     *
     * The snapshot is the requirement's: required, approved, fulfilled and
     * still unallocated per component. Call after TransfusionRequestResolver::
     * settle(), inside the same transaction.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordForRequirement(
        TransfusionRequest $request,
        RequestEventType $event,
        ?User $actor,
        ?BloodRequestStatus $from = null,
        ?string $note = null,
        array $meta = [],
    ): BloodRequestEvent {
        $lines = $this->requirementResolver->freshFigures($request);
        $request->loadMissing('items.component');
        $names = $request->items->pluck('component.name', 'id');

        return BloodRequestEvent::query()->create([
            'request_id' => null,
            'transfusion_request_id' => $request->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $request->status,
            'actor_id' => $actor?->id,
            'actor_facility_id' => $actor?->facility_id,
            'lines' => $lines->map(fn (array $line): array => [
                'transfusion_request_item_id' => $line['transfusion_request_item_id'],
                'component' => $names->get($line['transfusion_request_item_id']),
                'required' => $line['required'],
                // "requested" and "remaining" under the same keys an
                // allocation snapshot uses, so a timeline reads both alike.
                'requested' => $line['required'],
                'approved' => $line['approved'],
                'fulfilled' => $line['fulfilled'],
                'received' => $line['received'],
                'unallocated' => $line['unallocated'],
                'remaining' => $line['remaining'],
                'status' => $line['status']->value,
            ])->values()->all(),
            'meta' => $meta === [] ? null : $meta,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
        ]);
    }

    /**
     * Project one request's history for the timeline, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(BloodRequest $request): array
    {
        return $this->project($request->events()->with(self::TIMELINE_RELATIONS)->get());
    }

    /**
     * Project a requirement's history — its own events and every allocation's — oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timelineForRequirement(TransfusionRequest $request): array
    {
        return $this->project($request->events()->with(self::TIMELINE_RELATIONS)->get());
    }

    /**
     * @param  Collection<int, BloodRequestEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    private function project(Collection $events): array
    {
        return $events->map(fn (BloodRequestEvent $event): array => [
            'id' => $event->id,
            'event' => $event->event->value,
            'event_label' => $event->event->label(),
            'from_status' => $event->from_status?->value,
            'to_status' => $event->to_status?->value,
            'to_status_label' => $event->to_status?->label(),
            'actor' => $event->actor ? [
                'id' => $event->actor->id,
                'name' => trim($event->actor->first_name.' '.$event->actor->last_name),
            ] : null,
            'actor_facility' => $event->actorFacility ? [
                'id' => $event->actorFacility->id,
                'name' => $event->actorFacility->name,
            ] : null,
            // Which centre's share this happened to, when it happened to one.
            'allocation' => $event->request ? [
                'id' => $event->request->id,
                'reference_number' => $event->request->reference_number,
                'facility' => $event->request->targetFacility?->name,
            ] : null,
            'item' => $event->requestItem ? [
                'id' => $event->requestItem->id,
                'component' => $event->requestItem->component?->name,
            ] : null,
            'related_request' => $event->relatedRequest ? [
                'id' => $event->relatedRequest->id,
                'reference_number' => $event->relatedRequest->reference_number,
                'facility' => $event->relatedRequest->targetFacility?->name,
            ] : null,
            'lines' => array_map(fn (array $line): array => [
                ...$line,
                'status_label' => $this->lineStatusLabel($line),
            ], $event->lines ?? []),
            'unit_count' => count($event->unit_ids ?? []),
            'unit_ids' => $event->unit_ids ?? [],
            'meta' => $event->meta ?? (object) [],
            'note' => $event->note,
            'created_at' => $event->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Label a snapshot line with whichever vocabulary it was written in.
     *
     * @param  array<string, mixed>  $line
     */
    private function lineStatusLabel(array $line): ?string
    {
        $status = (string) ($line['status'] ?? '');

        return array_key_exists('required', $line)
            ? TransfusionLineStatus::tryFrom($status)?->label()
            : LineFulfilmentStatus::tryFrom($status)?->label();
    }
}
