<?php

namespace App\Service;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\FacilityStatus;
use App\Enums\HospitalUnitStatus;
use App\Enums\RequestEventType;
use App\Enums\RequestSource;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\Facility;
use App\Models\HospitalUnit;
use App\Models\RequestAllocation;
use App\Models\TransfusionRequest;
use App\Models\TransfusionRequestItem;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Repository\HospitalInventoryRepository;
use App\Repository\TransfusionRequestRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A hospital blood bank supplying another hospital's Blood Request from its own stock (project owner, 2026-11-28).
 *
 * Before, only blood centres could take a share of a hospital's Blood Request
 * (NetworkTransfusionRequestService). A hospital holding near-expiry bags it
 * will not use could only watch them expire while another hospital waited.
 *
 * The owner's rulings:
 *  - a hospital sees other hospitals' open requests without the patient's
 *    name: the requesting hospital, blood type, components and figures;
 *  - it picks bags from its shelf and sends them in one step, with no review
 *    in between, because it offers only what it already holds;
 *  - nothing is billed in RedAgos: the transfer is recorded, nothing more;
 *  - only near-expiry bags may go: Available, and expiring within
 *    config('hospital.supply_expiry_days') days, today included.
 *
 * The bags travel as a share of the request, written as any share is, but
 * addressed to the supplying hospital and released at once: one dispatched
 * hold per bag. So the requesting hospital receives them through its own
 * Receive and Stock Intake, untouched, and the request's figures count them
 * as they count a centre's. The giving hospital's custody row ends as
 * Transferred; the hold that first brought the bag there is marked passed on,
 * so the onward hold may claim it.
 */
class HospitalSupplyService
{
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly TransfusionRequestRepository $transfusionRepository,
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly HospitalInventoryRepository $inventoryRepository,
        private readonly TransfusionRequestResolver $resolver,
        private readonly RequestStatusResolver $shareResolver,
        private readonly TransfusionRequestProjector $transfusionProjector,
        private readonly TransfusionAllocationWriter $writer,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Other hospitals' open Blood Requests, with what this hospital could send for each.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $hospitalId = $this->requireFacilityId($user);
        $offerable = $this->inventoryRepository->supplyCountsByTypeAndComponent($hospitalId, OperationalDay::todayAsDate(), $this->windowDays());

        return $this->transfusionRepository
            ->paginateForHospitals($hospitalId, $filters, $perPage)
            ->through(fn (TransfusionRequest $request): array => $this->row($request, $hospitalId, $offerable));
    }

    /**
     * The bags this hospital may send for one request: per line still unallocated, first-expiring first.
     *
     * @return array<string, mixed>
     */
    public function candidates(User $user, int $id): array
    {
        $hospitalId = $this->requireFacilityId($user);
        $request = $this->findOthers($id, $hospitalId);
        $figures = $this->resolver->figures($request);
        $today = OperationalDay::todayAsDate();

        $open = $request->items->filter(fn (TransfusionRequestItem $item): bool => (int) ($figures->get($item->id)['unallocated'] ?? 0) > 0);

        $bags = $open->isEmpty() || $request->blood_type_id === null
            ? new Collection
            : $this->inventoryRepository
                ->supplyCandidates($hospitalId, (int) $request->blood_type_id, $open->pluck('component_id')->map(fn ($id): int => (int) $id)->all(), $today, $this->windowDays())
                ->groupBy(fn (HospitalUnit $unit): int => (int) $unit->bloodUnit?->component_id);

        return [
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'hospital' => $request->hospital?->name,
            'blood_type' => $request->bloodType?->code,
            'window_days' => $this->windowDays(),
            'lines' => $open->sortBy('id')->values()->map(fn (TransfusionRequestItem $item): array => [
                'transfusion_request_item_id' => $item->id,
                'component' => [
                    'id' => $item->component_id,
                    'name' => $item->component?->name,
                    'code' => $item->component?->labelCode(),
                ],
                'unallocated' => (int) ($figures->get($item->id)['unallocated'] ?? 0),
                'units' => ($bags->get((int) $item->component_id) ?? collect())->map(fn (HospitalUnit $unit): array => [
                    'unit_id' => $unit->unit_id,
                    'bag_number' => $unit->bloodUnit?->directDistribution?->bagNumberFor($unit->unit_id) ?? $unit->unit_id,
                    'expiry_date' => $unit->bloodUnit?->expiry_date?->toDateString(),
                    'days_remaining' => $unit->bloodUnit?->expiry_date ? OperationalDay::daysUntil($unit->bloodUnit->expiry_date) : null,
                    'storage_location' => $unit->storage_location,
                    'volume_ml' => $unit->bloodUnit?->volume_ml,
                ])->values()->all(),
            ])->all(),
        ];
    }

    /**
     * Send bags from this hospital's shelf to another hospital's Blood Request.
     *
     * @param  array<int, string>  $unitIds
     * @return array<string, mixed>
     */
    public function supply(User $user, int $id, array $unitIds): array
    {
        $hospitalId = $this->requireFacilityId($user);
        $unitIds = array_values(array_unique(array_map('strval', $unitIds)));

        $requesterId = TransfusionRequest::query()->whereKey($id)->value('facility_id')
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        if ((int) $requesterId === $hospitalId) {
            throw $this->refuse(404, 'request_not_found', 'Blood request not found.');
        }

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                $share = DB::transaction(fn (): BloodRequest => $this->persist($user, $hospitalId, (int) $requesterId, $id, $unitIds));

                $this->notifier->suppliedByHospital($share);

                $request = $this->findOthers($id, $hospitalId, allowClosed: true);
                $units = (int) $share->quantity;

                return [
                    'message' => "{$units} bag".($units === 1 ? '' : 's')." sent to {$request->hospital?->name} as {$share->reference_number}.",
                    'share' => [
                        'id' => $share->id,
                        'reference_number' => $share->reference_number,
                    ],
                    'request' => $this->row(
                        $request,
                        $hospitalId,
                        $this->inventoryRepository->supplyCountsByTypeAndComponent($hospitalId, OperationalDay::todayAsDate(), $this->windowDays())
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
     * Write the share, its released holds and the bags' new custody, under the requester's locks.
     *
     * Locks in the order every share writer takes them: the requesting
     * hospital's facility (its RQ numbering), then the requirement. The bags
     * come after, in custody-row order, as the expiry sweep takes them.
     *
     * @param  array<int, string>  $unitIds
     */
    private function persist(User $user, int $hospitalId, int $requesterId, int $id, array $unitIds): BloodRequest
    {
        $this->bloodRequestRepository->lockFacility($requesterId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $requirement = $this->transfusionRepository->lockById($id)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $requirement->loadMissing('hospital');

        if ($requirement->isClosed()) {
            throw $this->refuse(409, 'request_closed', "{$requirement->reference_number} is {$requirement->status->label()} and can no longer be supplied.");
        }

        if ($requirement->hospital?->status === FacilityStatus::Unregistered) {
            throw $this->refuse(409, 'hospital_not_registered', "{$requirement->hospital->name} is not on RedAgos.");
        }

        if ($unitIds === []) {
            throw ValidationException::withMessages(['unit_ids' => ['Pick at least one bag to send.']]);
        }

        $figures = $this->resolver->freshFigures($requirement);
        $requirement->loadMissing('items.component');
        $itemsByComponent = $requirement->items->keyBy(fn (TransfusionRequestItem $item): int => (int) $item->component_id);

        $bags = $this->lockBags($hospitalId, $unitIds);
        $today = OperationalDay::todayAsDate();
        $lastDay = CarbonImmutable::parse($today)->addDays($this->windowDays())->toDateString();

        // Each bag must be one this hospital may send, of the patient's blood
        // type and a component still unallocated on the request.
        $perItem = [];

        foreach ($bags as $bag) {
            $blood = $bag->bloodUnit;
            $expiry = $blood?->expiry_date?->toDateString();

            if ($bag->status !== HospitalUnitStatus::Available) {
                throw $this->refuse(409, 'unit_not_available', "Bag {$bag->unit_id} is {$bag->status->label()} and cannot be sent.");
            }

            if ($expiry === null || $expiry < $today || $expiry > $lastDay) {
                throw $this->refuse(409, 'unit_not_near_expiry', "Bag {$bag->unit_id} is not near expiry. Only bags expiring within {$this->windowDays()} days may be sent to another hospital.");
            }

            if ((int) $blood->blood_type_id !== (int) $requirement->blood_type_id) {
                throw $this->refuse(409, 'unit_wrong_type', "Bag {$bag->unit_id} is not {$requirement->bloodType?->code}.");
            }

            $item = $itemsByComponent->get((int) $blood->component_id)
                ?? throw $this->refuse(409, 'unit_wrong_component', "{$requirement->reference_number} does not ask for {$blood->component?->name}.");

            $perItem[$item->id][] = $bag;
        }

        foreach ($perItem as $itemId => $sent) {
            $unallocated = (int) ($figures->get($itemId)['unallocated'] ?? 0);

            if (count($sent) > $unallocated) {
                $name = $requirement->items->firstWhere('id', $itemId)?->component?->name ?? 'that component';

                throw $this->refuse(409, 'too_many_units', $unallocated > 0
                    ? "Only {$unallocated} unit(s) of {$name} are still unallocated."
                    : "Every unit of {$name} is already supplied or awaiting a facility's answer.");
            }
        }

        $hospital = Facility::query()->findOrFail($hospitalId);
        $from = $requirement->status;
        $lines = collect($perItem)->map(fn (array $sent, int $itemId): array => [
            'item' => $requirement->items->firstWhere('id', $itemId),
            'quantity' => count($sent),
        ])->values()->all();

        $share = $this->writer->create(
            $requirement,
            $hospital,
            $lines,
            $user,
            RequestSource::BloodBankPortal,
            RequestEventType::Submitted,
            "{$hospital->name} sent bags from its own stock for {$requirement->reference_number}.",
        );

        // Sent, not asked: nothing waits for a review.
        $share->forceFill(['reviewed_by' => $user->id, 'reviewed_at' => now()])->save();

        $shareLines = $share->items->keyBy(fn (BloodRequestItem $line): int => (int) $line->transfusion_request_item_id);
        $now = now();

        foreach ($perItem as $itemId => $sent) {
            /** @var BloodRequestItem $line */
            $line = $shareLines->get($itemId);

            foreach ($sent as $bag) {
                // The hold that brought the bag here claims it no longer, so
                // the onward hold below can.
                if ($bag->request_allocation_id !== null) {
                    RequestAllocation::query()->whereKey($bag->request_allocation_id)->update(['passed_on_at' => $now]);
                }

                $hold = RequestAllocation::query()->create([
                    'request_id' => $share->id,
                    'request_item_id' => $line->id,
                    'unit_id' => $bag->unit_id,
                    'allocated_at' => $now,
                    'allocated_by' => $user->id,
                    'status' => AllocationStatus::Released,
                    'released_at' => $now,
                    'released_by' => $user->id,
                ]);

                $bag->forceFill([
                    'status' => HospitalUnitStatus::Transferred,
                    'transferred_at' => $now,
                    'transferred_by' => $user->id,
                    'transfer_allocation_id' => $hold->id,
                ])->save();

                $this->auditLogger->record($user, 'hospital_inventory.transferred', $bag->bloodUnit, [
                    'facility_id' => $hospitalId,
                    'hospital_unit_id' => $bag->id,
                    'to_facility_id' => $requirement->facility_id,
                    'request_allocation_id' => $hold->id,
                    'reference_number' => $share->reference_number,
                    'new_status' => HospitalUnitStatus::Transferred->value,
                ]);
            }
        }

        $this->shareResolver->settle($share);

        $sentIds = $bags->pluck('unit_id')->all();

        $this->history->record(
            $share,
            RequestEventType::Released,
            $user,
            BloodRequestStatus::Pending,
            "Sent from {$hospital->name}'s own stock.",
            $sentIds,
        );

        $this->resolver->settle($requirement);

        $this->history->recordForRequirement(
            $requirement,
            RequestEventType::AllocationsAdded,
            $user,
            $from,
            "{$hospital->name} sent {$share->quantity} bag(s) from its own stock.",
            ['allocations' => [$share->reference_number]],
        );

        $this->auditLogger->record($user, 'request.hospital_supplied', $share, [
            'facility_id' => $hospitalId,
            'hospital_facility_id' => $requirement->facility_id,
            'reference_number' => $share->reference_number,
            'transfusion_reference_number' => $requirement->reference_number,
            'quantity' => $share->quantity,
            'units' => $sentIds,
        ]);

        $share->setRelation('transfusionRequest', $requirement);
        $share->setRelation('targetFacility', $hospital);

        return $share;
    }

    /**
     * Lock this hospital's bags by number, in custody-row order.
     *
     * @param  array<int, string>  $unitIds
     * @return Collection<int, HospitalUnit>
     */
    private function lockBags(int $hospitalId, array $unitIds): Collection
    {
        $ordered = $this->inventoryRepository->unitIdsInLockOrder($hospitalId, $unitIds);
        $missing = array_values(array_diff($unitIds, $ordered));

        if ($missing !== []) {
            throw $this->refuse(404, 'unit_not_found', "Bag {$missing[0]} is not in this blood bank's stock.");
        }

        return collect(array_values(array_unique($ordered)))
            ->map(fn (string $unitId): HospitalUnit => $this->inventoryRepository->lockUnitForFacility($unitId, $hospitalId)
                ?? throw $this->refuse(404, 'unit_not_found', "Bag {$unitId} is not in this blood bank's stock."))
            ->each(fn (HospitalUnit $bag) => $bag->load(['bloodUnit.component', 'bloodUnit.bloodType']));
    }

    /**
     * One request as a hospital may see it: no patient, and what this hospital could send.
     *
     * @param  array<string, int>  $offerable  supply counts keyed "blood_type_id:component_id"
     * @return array<string, mixed>
     */
    private function row(TransfusionRequest $request, int $hospitalId, array $offerable): array
    {
        $figures = $this->resolver->figures($request);
        $isOpen = ! $request->isClosed();

        $lines = $request->items->sortBy('id')->values()->map(function (TransfusionRequestItem $item) use ($figures, $offerable, $request): array {
            $unallocated = (int) ($figures->get($item->id)['unallocated'] ?? 0);
            $held = (int) ($offerable[((int) $request->blood_type_id).':'.((int) $item->component_id)] ?? 0);

            return [
                'transfusion_request_item_id' => $item->id,
                'component' => [
                    'id' => $item->component_id,
                    'name' => $item->component?->name,
                    'code' => $item->component?->labelCode(),
                ],
                'required' => (int) $item->quantity,
                'fulfilled' => (int) ($figures->get($item->id)['fulfilled'] ?? 0),
                'unallocated' => $unallocated,
                // Near-expiry bags of this type and component on this shelf,
                // and how many of them this line could take.
                'near_expiry_here' => $held,
                'sendable' => min($unallocated, $held),
            ];
        });

        $unallocated = (int) $lines->sum('unallocated');
        $sendable = (int) $lines->sum('sendable');

        $shares = $request->facilityAllocations
            ->reject(fn (BloodRequest $share): bool => in_array($share->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true))
            ->sortBy('id')
            ->values();

        $blocked = match (true) {
            ! $isOpen => 'This request is closed.',
            $unallocated === 0 => 'Every unit is already supplied or awaiting a facility\'s answer.',
            $sendable === 0 => 'No near-expiry bag of this type here.',
            default => null,
        };

        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'hospital' => [
                'id' => $request->facility_id,
                'name' => $request->hospital?->name,
            ],
            'blood_type' => [
                'id' => $request->blood_type_id,
                'code' => $request->bloodType?->code,
            ],
            'urgency_level' => $request->urgency_level->value,
            'urgency_label' => $request->urgency_level->label(),
            'is_emergency' => $request->urgency_level->isPrioritised(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'is_open' => $isOpen,
            'request_date' => $request->request_date?->toIso8601String(),
            'needed_by' => $request->needed_by?->toIso8601String(),
            'lines' => $lines->all(),
            'suppliers' => $shares->map(fn (BloodRequest $share): array => [
                'reference_number' => $share->reference_number,
                'name' => $share->targetFacility?->name,
                'is_mine' => (int) $share->target_facility_id === $hospitalId,
                'quantity' => (int) $share->quantity,
                'status_label' => $this->transfusionProjector->allocationStatusLabel($share),
            ])->all(),
            'unallocated_quantity' => $unallocated,
            'sendable_quantity' => $sendable,
            'can_supply' => $blocked === null,
            'supply_blocked' => $blocked,
        ];
    }

    /**
     * Another hospital's requirement, open unless asked otherwise, as the list reads it.
     */
    private function findOthers(int $id, int $hospitalId, bool $allowClosed = false): TransfusionRequest
    {
        $request = $this->transfusionRepository->findForCentres($id);

        if ($request === null || (int) $request->facility_id === $hospitalId || $request->hospital?->status === FacilityStatus::Unregistered) {
            throw $this->refuse(404, 'request_not_found', 'Blood request not found.');
        }

        if (! $allowClosed && $request->isClosed()) {
            throw $this->refuse(409, 'request_closed', "{$request->reference_number} is {$request->status->label()} and can no longer be supplied.");
        }

        return $request;
    }

    private function windowDays(): int
    {
        return max(0, (int) config('hospital.supply_expiry_days', 7));
    }

    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
