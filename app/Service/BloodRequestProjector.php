<?php

namespace App\Service;

use App\Enums\LineFulfilmentStatus;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\BloodRequestWalkIn;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\TransfusionRequest;
use App\Models\TransfusionRequestItem;
use App\Models\User;
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
 *
 * Request and fulfilment are kept apart in the shape as they are in the data:
 * `quantity` and each line's `quantity` are what was asked for and never
 * change; the fulfilment figures beside them say what was provided.
 */
class BloodRequestProjector
{
    public function __construct(
        private readonly RequestStatusResolver $resolver
    ) {}

    /**
     * Project a request for the API.
     *
     * @return array<string, mixed>
     */
    public function project(BloodRequest $request, bool $withAllocations = false): array
    {
        $allocations = $request->relationLoaded('allocations') ? $request->allocations : collect();
        $claimed = $allocations->filter(fn (RequestAllocation $a): bool => $a->status->claimsUnit());

        // The SQL aggregate wins where the query provided one; the loaded
        // relation is the fallback for a request loaded some other way.
        $allocatedCount = $request->allocated_count ?? $claimed->count();
        $receivedCount = $request->received_count ?? $claimed->whereNotNull('received_at')->count();
        $quantity = $request->quantity;

        $figures = $this->figuresFor($request);
        $fulfilled = (int) $figures->sum('fulfilled');

        $projection = [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'requesting_facility' => $this->facilityStub($request->requestingFacility),
            'target_facility' => $this->facilityStub($request->targetFacility),
            'request_purpose' => $request->request_purpose->value,
            'purpose_label' => $request->request_purpose->label(),
            'request_source' => $request->request_source->value,
            'source_label' => $request->request_source->label(),
            'is_walk_in' => $request->request_source->isWalkIn(),
            'requester_name' => $this->personName($request->relationLoaded('requester') ? $request->requester : null),
            'recorder_name' => $this->personName($request->relationLoaded('recorder') ? $request->recorder : null),
            'patient' => $this->patient($request),
            'blood_type' => [
                'id' => $request->blood_type_id,
                'code' => $request->bloodType?->code,
            ],
            'items' => $this->items($request, $figures, $claimed, $withAllocations),
            'quantity' => $quantity,
            'urgency_level' => $request->urgency_level->value,
            'urgency_label' => $request->urgency_level->label(),
            'is_emergency' => $request->urgency_level->isPrioritised(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'is_open' => ! $request->isClosed(),
            'closed_at' => $request->closed_at?->toIso8601String(),
            // Derived rather than stored: the request status says how far the
            // request has got, and these say how much of it is covered.
            'allocated_count' => $allocatedCount,
            'received_count' => $receivedCount,
            // What this facility still has to find: asked for, less what is
            // held or closed. Before lines could be closed this was simply
            // quantity less allocated_count, and it still is for any request
            // that has none closed.
            'outstanding_quantity' => $figures->isNotEmpty()
                ? (int) $figures->sum('allocatable')
                : max(0, $quantity - $allocatedCount),
            // Fulfilment is counted at dispatch: released units.
            'fulfilled_quantity' => $fulfilled,
            'remaining_quantity' => max(0, $quantity - $fulfilled),
            'rejection_reason' => $request->rejection_reason,
            'request_date' => $request->request_date?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'fulfilled_at' => $request->fulfilled_at?->toIso8601String(),
            'transfusion_request' => $this->transfusionRequest($request),
            'walk_in' => $this->walkIn($request),
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
     * Work out per-line figures when the request carries what they are read from.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function figuresFor(BloodRequest $request): Collection
    {
        if (! $request->relationLoaded('items') || ! $request->relationLoaded('allocations')) {
            return collect();
        }

        return $this->resolver->figures($request);
    }

    /**
     * Project the components a request asks for, one entry per line.
     *
     * The requested quantity is the line as the form was filled in. Everything
     * after it is fulfilment.
     *
     * @param  Collection<int, array<string, mixed>>  $figures
     * @param  Collection<int, RequestAllocation>  $claimed
     * @return array<int, array<string, mixed>>
     */
    private function items(BloodRequest $request, Collection $figures, Collection $claimed, bool $withCoverage): array
    {
        $heldPerItem = $claimed->groupBy('request_item_id');

        return $request->items
            ->sortBy('id')
            ->values()
            ->map(function (BloodRequestItem $item) use ($figures, $heldPerItem, $withCoverage): array {
                $line = [
                    'id' => $item->id,
                    'transfusion_request_item_id' => $item->transfusion_request_item_id,
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
                    'closed_at' => $item->closed_at?->toIso8601String(),
                    'closure_reason' => $item->closure_reason?->value,
                    'closure_reason_label' => $item->closure_reason?->label(),
                    'closure_note' => $item->closure_note,
                ];

                $figure = $figures->get($item->id);

                if ($figure !== null) {
                    /** @var LineFulfilmentStatus $status */
                    $status = $figure['status'];

                    $line += [
                        'reserved_quantity' => $figure['reserved'],
                        'fulfilled_quantity' => $figure['fulfilled'],
                        'received_quantity' => $figure['received'],
                        'remaining_quantity' => $figure['remaining'],
                        'allocatable_quantity' => $figure['allocatable'],
                        'line_status' => $status->value,
                        'line_status_label' => $status->label(),
                    ];
                }

                if ($withCoverage) {
                    $line['allocated_count'] = $heldPerItem->get($item->id)?->count() ?? 0;
                    $line['outstanding_quantity'] = $figure['allocatable']
                        ?? max(0, $item->quantity - $line['allocated_count']);
                }

                return $line;
            })
            ->all();
    }

    /**
     * Project the patient requirement this facility allocation is a share of.
     *
     * A stub only: a centre is shown which requirement, and how much of each
     * component the patient needs in all, so it can read its own share against
     * it — "you are asked for 1 of the 5 the patient needs". It is not shown
     * which other centres were asked, or what they answered.
     *
     * @return array<string, mixed>|null
     */
    private function transfusionRequest(BloodRequest $request): ?array
    {
        if ($request->transfusion_request_id === null || ! $request->relationLoaded('transfusionRequest')) {
            return null;
        }

        /** @var TransfusionRequest|null $requirement */
        $requirement = $request->transfusionRequest;

        if ($requirement === null) {
            return null;
        }

        return [
            'id' => $requirement->id,
            'reference_number' => $requirement->reference_number,
            'status' => $requirement->status->value,
            'status_label' => $requirement->status->label(),
            'is_open' => ! $requirement->isClosed(),
            'required' => $requirement->relationLoaded('items')
                ? $requirement->items->sortBy('id')->values()->map(fn (TransfusionRequestItem $item): array => [
                    'transfusion_request_item_id' => $item->id,
                    'component_id' => $item->component_id,
                    'component' => $item->relationLoaded('component') ? $item->component?->name : null,
                    'quantity' => (int) $item->quantity,
                ])->all()
                : [],
        ];
    }

    /**
     * Project the representative and phone verification of a walk-in.
     *
     * @return array<string, mixed>|null
     */
    private function walkIn(BloodRequest $request): ?array
    {
        if (! $request->relationLoaded('walkIn')) {
            return null;
        }

        return $this->walkInBlock($request->walkIn);
    }

    /**
     * Project one walk-in row. Shared with TransfusionRequestProjector.
     *
     * @return array<string, mixed>|null
     */
    public function walkInBlock(?BloodRequestWalkIn $walkIn): ?array
    {
        if ($walkIn === null) {
            return null;
        }

        return [
            'representative' => [
                'name' => $walkIn->representative_name,
                'relationship' => $walkIn->representative_relationship,
                'contact' => $walkIn->representative_contact,
                'id_type' => $walkIn->representative_id_type?->value,
                'id_type_label' => $walkIn->representative_id_type?->label(),
                'id_number' => $walkIn->representative_id_number,
            ],
            'presented_reference' => $walkIn->presented_reference,
            'attending_physician' => $walkIn->attending_physician,
            'patient_ward' => $walkIn->patient_ward,
            'patient_record_number' => $walkIn->patient_record_number,
            'verification' => [
                'method' => 'phone',
                'verifier_name' => $walkIn->verifier_name,
                'verifier_position' => $walkIn->verifier_position,
                'verifier_contact' => $walkIn->verifier_contact,
                'verified_at' => $walkIn->verified_at?->toIso8601String(),
                'recorded_by' => $this->personName(
                    $walkIn->relationLoaded('verificationRecorder') ? $walkIn->verificationRecorder : null
                ),
                'notes' => $walkIn->verification_notes,
            ],
            'duplicate_acknowledgement' => $walkIn->duplicate_acknowledgement,
        ];
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

        return $this->patientBlock($request);
    }

    /**
     * Project the patient columns a request or a requirement carries.
     *
     * @return array<string, mixed>
     */
    public function patientBlock(BloodRequest|TransfusionRequest $request): array
    {
        return [
            'surname' => $request->patient_surname,
            'first_name' => $request->patient_first_name,
            'middle_name' => $request->patient_middle_name,
            'full_name' => $request->patientFullName(),
            'age' => $request->patient_age,
            'sex' => $request->patient_sex,
        ];
    }

    public function personName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim($user->first_name.' '.$user->last_name);

        return $name === '' ? null : $name;
    }

    /**
     * Project the minimum a client needs to name a facility.
     *
     * @return array<string, mixed>|null
     */
    public function facilityStub(?Facility $facility): ?array
    {
        return $facility ? [
            'id' => $facility->id,
            'name' => $facility->name,
            'address' => $facility->address,
        ] : null;
    }
}
