<?php

namespace App\Service;

use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\Facility;
use App\Models\RequestAllocation;
use Illuminate\Support\Collection;

/**
 * The one shape a blood request takes when it leaves the API.
 *
 * This projection was written twice — once for the requesting side and once for
 * the fulfilling side — and the two copies had already drifted over which keys
 * they carried. Adding the request form would have made a third copy, so the
 * two were collapsed into this. Both sides now receive the same keys; the
 * fulfilling side simply ignores the target facility, which always means
 * itself.
 */
class BloodRequestProjector
{
    /**
     * Project a request for the API.
     *
     * @return array<string, mixed>
     */
    public function project(BloodRequest $request, bool $withAllocations = false): array
    {
        $allocations = $request->relationLoaded('allocations') ? $request->allocations : collect();
        $claimed = $allocations->filter(fn (RequestAllocation $a): bool => $a->status->claimsUnit());

        // The SQL aggregate wins where the query provided one. A listing does
        // not load allocations, so counting the relation there would report
        // every request as uncovered; the relation is only the fallback for a
        // single request loaded with its allocations.
        $allocatedCount = $request->allocated_count ?? $claimed->count();
        $receivedCount = $request->received_count ?? $claimed->whereNotNull('received_at')->count();
        $quantity = $request->quantity;

        $projection = [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'requesting_facility' => $this->facilityStub($request->requestingFacility),
            'target_facility' => $this->facilityStub($request->targetFacility),
            'request_purpose' => $request->request_purpose->value,
            'purpose_label' => $request->request_purpose->label(),
            'patient' => $this->patient($request),
            'blood_type' => [
                'id' => $request->blood_type_id,
                'code' => $request->bloodType?->code,
            ],
            'items' => $this->items($request, $claimed, $withAllocations),
            'quantity' => $quantity,
            'urgency_level' => $request->urgency_level->value,
            'urgency_label' => $request->urgency_level->label(),
            'is_emergency' => $request->urgency_level->isPrioritised(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            // Derived rather than stored: the request status says how far the
            // request has got, and these say how much of it is covered.
            'allocated_count' => $allocatedCount,
            'received_count' => $receivedCount,
            'outstanding_quantity' => max(0, $quantity - $allocatedCount),
            'rejection_reason' => $request->rejection_reason,
            'request_date' => $request->request_date?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'fulfilled_at' => $request->fulfilled_at?->toIso8601String(),
        ];

        if ($withAllocations) {
            $projection['allocations'] = $allocations
                ->map(fn (RequestAllocation $allocation): array => [
                    'id' => $allocation->id,
                    'request_item_id' => $allocation->request_item_id,
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
     * Project the components a request asks for, one entry per line.
     *
     * Per-line coverage is only reported when the caller loaded allocations. A
     * listing has not, and reporting every line as uncovered would be worse
     * than not reporting it at all.
     *
     * @param  Collection<int, RequestAllocation>  $claimed
     * @return array<int, array<string, mixed>>
     */
    private function items(BloodRequest $request, Collection $claimed, bool $withCoverage): array
    {
        $heldPerItem = $claimed->groupBy('request_item_id');

        return $request->items
            ->map(function (BloodRequestItem $item) use ($heldPerItem, $withCoverage): array {
                $line = [
                    'id' => $item->id,
                    'component' => [
                        'id' => $item->component_id,
                        'name' => $item->component?->name,
                    ],
                    'quantity' => $item->quantity,
                    'indication_code' => $item->indication_code?->value,
                    'indication_label' => $item->indication_code?->label(),
                    'indication_description' => $item->indication_code?->description(),
                    'indication_other' => $item->indication_other,
                    'indication_text' => $item->indicationText(),
                ];

                if ($withCoverage) {
                    $held = $heldPerItem->get($item->id)?->count() ?? 0;

                    $line['allocated_count'] = $held;
                    $line['outstanding_quantity'] = max(0, $item->quantity - $held);
                }

                return $line;
            })
            ->all();
    }

    /**
     * Project the patient a transfusion is for, or null when there is no patient.
     *
     * @return array<string, mixed>|null
     */
    private function patient(BloodRequest $request): ?array
    {
        if (! $request->request_purpose->requiresPatient()) {
            return null;
        }

        return [
            'surname' => $request->patient_surname,
            'first_name' => $request->patient_first_name,
            'middle_name' => $request->patient_middle_name,
            'full_name' => $request->patientFullName(),
            'age' => $request->patient_age,
            'sex' => $request->patient_sex,
        ];
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
}
