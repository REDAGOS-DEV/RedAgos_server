<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\LineFulfilmentStatus;
use App\Enums\RequestEventType;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\BloodRequestItem;
use App\Models\User;

/**
 * Writes and reads a blood request's history.
 *
 * Every change to a request lands here as one event: who did it, from which
 * facility, the status it moved between, and a snapshot of every line at that
 * moment. The snapshot is what makes fulfilment auditable — "FFP 1 of 2
 * released, 1 remaining" is recorded as it stood, not reconstructed later from
 * allocations that may since have moved.
 *
 * Not the audit log. audit_logs is the security trail and carries identifiers
 * only; this is what the two portals show as the request's timeline.
 */
class BloodRequestHistory
{
    public function __construct(
        private readonly RequestStatusResolver $resolver
    ) {}

    /**
     * Record one event against a request, as it stands now.
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
                'forwarded' => $line['forwarded'],
                'remaining' => $line['remaining'],
                'status' => $line['status']->value,
            ])->values()->all(),
            'unit_ids' => $unitIds === [] ? null : array_values($unitIds),
            'meta' => $meta === [] ? null : $meta,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
        ]);
    }

    /**
     * Project a request's history for the timeline, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(BloodRequest $request): array
    {
        $events = $request->events()
            ->with([
                'actor:id,first_name,last_name',
                'actorFacility:id,name',
                'relatedRequest:id,reference_number,target_facility_id',
                'relatedRequest.targetFacility:id,name',
                'requestItem.component:id,name',
            ])
            ->get();

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
                'status_label' => LineFulfilmentStatus::tryFrom((string) ($line['status'] ?? ''))?->label(),
            ], $event->lines ?? []),
            'unit_count' => count($event->unit_ids ?? []),
            'unit_ids' => $event->unit_ids ?? [],
            'meta' => $event->meta ?? (object) [],
            'note' => $event->note,
            'created_at' => $event->created_at?->toIso8601String(),
        ])->all();
    }
}
