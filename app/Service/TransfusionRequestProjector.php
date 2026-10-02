<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
use App\Enums\TransfusionLineStatus;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use App\Models\TransfusionRequestItem;

/**
 * The shape a Patient Transfusion Request takes when it leaves the API.
 *
 * The requirement and its fulfilment side by side: each component's required
 * quantity, which never changes, beside what was asked of facilities, what is
 * still awaiting an answer, what is approved (reserved or released), released,
 * received, still remaining, and still unallocated. The facility allocations
 * follow, each in the same shape a single blood request always had.
 */
class TransfusionRequestProjector
{
    public function __construct(
        private readonly TransfusionRequestResolver $resolver,
        private readonly BloodRequestProjector $allocationProjector
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(TransfusionRequest $request, bool $withAllocations = true): array
    {
        $lines = $this->resolver->figures($request);

        $totals = [];

        foreach (['required', 'requested', 'awaiting', 'approved', 'fulfilled', 'received', 'remaining', 'unallocated'] as $figure) {
            $totals[$figure] = (int) $lines->sum($figure);
        }

        $projection = [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'request_purpose' => RequestPurpose::PatientTransfusion->value,
            'purpose_label' => RequestPurpose::PatientTransfusion->label(),
            'request_source' => $request->request_source->value,
            'source_label' => $request->request_source->label(),
            'is_walk_in' => $request->request_source === RequestSource::BloodCenterWalkIn,
            'requesting_facility' => $this->allocationProjector->facilityStub($request->hospital),
            'requester_name' => $this->allocationProjector->personName($request->requester),
            'recorder_name' => $this->allocationProjector->personName($request->recorder),
            'patient' => $this->allocationProjector->patientBlock($request),
            'blood_type' => [
                'id' => $request->blood_type_id,
                'code' => $request->bloodType?->code,
            ],
            'urgency_level' => $request->urgency_level->value,
            'urgency_label' => $request->urgency_level->label(),
            'is_emergency' => $request->urgency_level->isPrioritised(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'is_open' => ! $request->isClosed(),
            'needs_allocation' => ! $request->isClosed() && $this->resolver->needsAllocation($lines),
            'totals' => $totals,
            'lines' => $request->items->sortBy('id')->values()
                ->map(fn (TransfusionRequestItem $item): array => $this->line($item, $lines->get($item->id)))
                ->all(),
            'allocation_count' => $request->facilityAllocations->count(),
            'facilities' => $request->facilityAllocations
                ->map(fn (BloodRequest $allocation): ?string => $allocation->targetFacility?->name)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'internal_stock_checked_at' => $request->internal_stock_checked_at?->toIso8601String(),
            'request_date' => $request->request_date?->toIso8601String(),
            'fulfilled_at' => $request->fulfilled_at?->toIso8601String(),
            'closed_at' => $request->closed_at?->toIso8601String(),
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $request->cancellation_reason,
        ];

        if ($withAllocations) {
            $projection['allocations'] = $request->facilityAllocations
                ->map(fn (BloodRequest $allocation): array => [
                    ...$this->allocationProjector->project($allocation, withAllocations: true),
                    // The allocation's own status in the words a hospital uses
                    // about a share it asked a centre for.
                    'allocation_status_label' => $this->allocationStatusLabel($allocation),
                ])
                ->all();

            // The walk-in that brought the patient's need to a counter, when it
            // began there: the earliest allocation recorded at a centre.
            $projection['walk_in'] = $this->allocationProjector->walkInBlock(
                $request->facilityAllocations
                    ->first(fn (BloodRequest $allocation): bool => $allocation->relationLoaded('walkIn') && $allocation->walkIn !== null)
                    ?->walkIn
            );
        }

        return $projection;
    }

    /**
     * @param  array<string, mixed>|null  $figure
     * @return array<string, mixed>
     */
    private function line(TransfusionRequestItem $item, ?array $figure): array
    {
        /** @var TransfusionLineStatus|null $status */
        $status = $figure['status'] ?? null;

        return [
            'id' => $item->id,
            'component' => [
                'id' => $item->component_id,
                'name' => $item->component?->name,
            ],
            'quantity' => (int) $item->quantity,
            'indication_code' => $item->indication_code?->value,
            'indication_label' => $item->indication_code?->label(),
            'indication_text' => $item->indicationText(),
            'requested' => $figure['requested'] ?? 0,
            'awaiting' => $figure['awaiting'] ?? 0,
            'approved' => $figure['approved'] ?? 0,
            'fulfilled' => $figure['fulfilled'] ?? 0,
            'received' => $figure['received'] ?? 0,
            'remaining' => $figure['remaining'] ?? (int) $item->quantity,
            'unallocated' => $figure['unallocated'] ?? 0,
            'closed_at' => $item->closed_at?->toIso8601String(),
            'closure_note' => $item->closure_note,
            'line_status' => $status?->value,
            'line_status_label' => $status?->label(),
        ];
    }

    private function allocationStatusLabel(BloodRequest $allocation): string
    {
        return match ($allocation->status) {
            BloodRequestStatus::Pending => 'Awaiting review',
            BloodRequestStatus::Processing => 'Approved — units reserved',
            BloodRequestStatus::Partial => $allocation->isClosed() ? 'Released — rest unavailable' : 'Partly released',
            BloodRequestStatus::Fulfilled => 'Released',
            BloodRequestStatus::Rejected => 'Rejected',
            BloodRequestStatus::Cancelled => 'Withdrawn',
        };
    }
}
