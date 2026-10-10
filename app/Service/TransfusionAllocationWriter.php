<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestEventType;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use App\Models\TransfusionRequestItem;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The one way a facility allocation comes into being.
 *
 * Portal creation, "allocate remaining" and walk-ins all ask centres for
 * shares of a patient's requirement, and every one of those shares has to be
 * written the same way: addressed to one centre, in the hospital's RQ
 * sequence, carrying the patient, blood type, urgency and each component's
 * certified indication copied from the requirement, and linked back to the
 * requirement line it is a share of. Written once, here.
 *
 * The caller holds the hospital's facility lock (the RQ sequence) and the
 * requirement's row lock (so no two writers can hand out the same unallocated
 * unit), and notifies the centres after its transaction commits.
 */
class TransfusionAllocationWriter
{
    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly AvailabilityRepository $availabilityRepository,
        private readonly BloodRequestHistory $history,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Check a set of requested shares against what each requirement line still has unallocated.
     *
     * Shares arrive as [{facility_id, lines: [{component_id, quantity}]}]. The
     * same centre may not appear twice, a component must be on the
     * requirement, and no component may be asked for more than its
     * unallocated quantity across all the shares together. Returns the shares
     * resolved to their facilities and requirement lines.
     *
     * Call with the requirement's items.component and facilityAllocations
     * loaded — TransfusionRequestResolver::freshFigures() loads the latter.
     *
     * @param  array<int, array<string, mixed>>  $shares
     * @param  Collection<int, array<string, mixed>>  $figures  TransfusionRequestResolver figures, keyed by line id
     * @return array<int, array{facility: Facility, lines: array<int, array{item: TransfusionRequestItem, quantity: int}>}>
     */
    public function resolveShares(TransfusionRequest $request, array $shares, Collection $figures, string $field = 'allocations'): array
    {
        $itemsByComponent = $request->items->keyBy('component_id');
        $asked = [];
        $seenFacilities = [];
        $resolved = [];

        foreach (array_values($shares) as $index => $share) {
            $facilityId = (int) ($share['facility_id'] ?? 0);

            if (isset($seenFacilities[$facilityId])) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.facility_id" => ['Ask each facility once; put every component for it on the same allocation.'],
                ]);
            }

            $seenFacilities[$facilityId] = true;
            $facility = $this->requireEligible($facilityId, (int) $request->facility_id, "{$field}.{$index}.facility_id");

            // A centre still reviewing an earlier ask for this patient answers
            // that one first; a second ask beside it would only be reviewed
            // twice. Once it has answered — approved, part-supplied or refused —
            // it may be asked again.
            $reviewing = $request->facilityAllocations->first(
                fn (BloodRequest $allocation): bool => (int) $allocation->target_facility_id === $facilityId
                    && $allocation->status === BloodRequestStatus::Pending
            );

            if ($reviewing !== null) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.facility_id" => [
                        "{$facility->name} is still reviewing {$reviewing->reference_number} for this patient. Wait for its answer, or withdraw it first.",
                    ],
                ]);
            }
            $lines = [];

            foreach (array_values($share['lines'] ?? []) as $lineIndex => $line) {
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($quantity < 1) {
                    continue;
                }

                /** @var TransfusionRequestItem|null $item */
                $item = $itemsByComponent->get((int) ($line['component_id'] ?? 0));

                if ($item === null) {
                    throw ValidationException::withMessages([
                        "{$field}.{$index}.lines.{$lineIndex}.component_id" => ['That component is not on the patient\'s requirement.'],
                    ]);
                }

                $asked[$item->id] = ($asked[$item->id] ?? 0) + $quantity;
                $lines[] = ['item' => $item, 'quantity' => $quantity];
            }

            if ($lines !== []) {
                $resolved[] = ['facility' => $facility, 'lines' => $lines];
            }
        }

        if ($resolved === []) {
            throw ValidationException::withMessages([
                $field => ['Ask at least one facility for at least one unit.'],
            ]);
        }

        // Never more than the patient still needs from somebody. Checked across
        // every share together, so three facilities asked for two each cannot
        // add up past what is unallocated.
        foreach ($asked as $itemId => $quantity) {
            $unallocated = (int) ($figures->get($itemId)['unallocated'] ?? 0);

            if ($quantity > $unallocated) {
                $name = $request->items->firstWhere('id', $itemId)?->component?->name ?? 'that component';

                throw ValidationException::withMessages([
                    $field => [$unallocated > 0
                        ? "Only {$unallocated} unit(s) of {$name} are still unallocated; you asked facilities for {$quantity}."
                        : "Every unit of {$name} is already approved or awaiting a facility's answer."],
                ]);
            }
        }

        return $resolved;
    }

    /**
     * Write one facility allocation for a locked requirement.
     *
     * @param  array<int, array{item: TransfusionRequestItem, quantity: int}>  $lines
     */
    public function create(
        TransfusionRequest $request,
        Facility $facility,
        array $lines,
        User $user,
        RequestSource $source,
        RequestEventType $event = RequestEventType::Submitted,
        ?string $note = null,
    ): BloodRequest {
        $allocation = BloodRequest::query()->create([
            'reference_number' => $this->bloodRequestRepository->nextReference((int) $request->facility_id),
            'transfusion_request_id' => $request->id,
            'facility_id' => $request->facility_id,
            'target_facility_id' => $facility->id,
            // The hospital user who asked, from the portal; on a walk-in
            // nobody at the hospital did, and the centre staff member who
            // recorded it is named instead.
            'requested_by' => $source->isWalkIn() ? null : $user->id,
            'recorded_by' => $source->isWalkIn() ? $user->id : null,
            'request_purpose' => RequestPurpose::PatientTransfusion,
            'request_source' => $source,
            'patient_surname' => $request->patient_surname,
            'patient_first_name' => $request->patient_first_name,
            'patient_middle_name' => $request->patient_middle_name,
            'patient_age' => $request->patient_age,
            'patient_sex' => $request->patient_sex,
            'blood_type_id' => $request->blood_type_id,
            'urgency_level' => $request->urgency_level,
            'status' => BloodRequestStatus::Pending,
            'request_date' => now(),
        ]);

        foreach ($lines as $line) {
            $allocation->items()->create([
                'transfusion_request_item_id' => $line['item']->id,
                'blood_type_id' => $request->blood_type_id,
                'component_id' => $line['item']->component_id,
                'quantity' => $line['quantity'],
                'indication_code' => $line['item']->indication_code,
                'indication_other' => $line['item']->indication_other,
            ]);
        }

        $allocation->load(['items.component', 'items.bloodType', 'targetFacility', 'requestingFacility', 'bloodType']);

        $this->history->record(
            $allocation,
            $event,
            $user,
            null,
            $note ?? "Part of {$request->reference_number}.",
        );

        $this->auditLogger->record($user, 'request.allocation_created', $allocation, [
            'facility_id' => $user->facility_id,
            'hospital_facility_id' => $request->facility_id,
            'target_facility_id' => $facility->id,
            'reference_number' => $allocation->reference_number,
            'transfusion_reference_number' => $request->reference_number,
            'quantity' => $allocation->quantity,
            'components' => $allocation->items->pluck('component.name')->all(),
            'request_source' => $source->value,
        ]);

        return $allocation;
    }

    /**
     * Resolve a centre a share may be asked of: an approved blood centre, never the hospital itself.
     */
    private function requireEligible(int $facilityId, int $hospitalId, string $field): Facility
    {
        $facility = Facility::query()->with('facilityType')->find($facilityId);

        if ($facilityId === $hospitalId || ! $facility || ! $this->availabilityRepository->isEligibleTarget($facility)) {
            throw ValidationException::withMessages([
                $field => ['That facility cannot receive blood requests.'],
            ]);
        }

        return $facility;
    }
}
