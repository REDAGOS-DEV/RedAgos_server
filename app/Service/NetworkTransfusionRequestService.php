<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\FacilityStatus;
use App\Enums\RequestEventType;
use App\Enums\RequestSource;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use App\Models\TransfusionRequestItem;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Repository\TransfusionRequestRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every hospital's Blood Requests, as any blood centre sees them (project owner, 2026-11-19).
 *
 * A Blood Request (a Patient Transfusion Request, PTR-…) is the hospital's.
 * The share of it a centre was asked for — its facility allocation, RQ-… —
 * is worked in that centre's Incoming Requests only. Before, a centre never
 * saw a Blood Request it held no share of, so a walk-in counter that found
 * one in the duplicate check could not find it again anywhere.
 *
 * Now every centre reads every one, from Request Fulfillment, as a stub:
 * the hospital, the patient's name, blood type, each component's figures,
 * the status, and which centre holds each share. Nothing more — no age, sex,
 * ward or physician, no bag numbers, no billing and no watcher's details
 * (project owner, same day).
 *
 * The one thing a centre may do here is add its own share of units nobody
 * has been asked for yet, as a walk-in continuation can. It is written as
 * the hospital's own asks are, a portal share billed and dispatched to the
 * hospital, with the centre's staff member named as its recorder, and the
 * hospital is told.
 */
class NetworkTransfusionRequestService
{
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly TransfusionRequestRepository $transfusionRepository,
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly TransfusionRequestResolver $resolver,
        private readonly TransfusionRequestProjector $transfusionProjector,
        private readonly TransfusionAllocationWriter $writer,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Every hospital's Blood Requests, open ones first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $centreId = $this->requireFacilityId($user);

        return $this->transfusionRepository
            ->paginateForCentres($filters, $perPage)
            ->through(fn (TransfusionRequest $request): array => $this->row($request, $centreId));
    }

    /**
     * Add this centre's share of a Blood Request's unallocated units.
     *
     * Under the hospital's facility lock, which its RQ numbering queues
     * behind, then the requirement's, so no two writers can hand out the same
     * unallocated unit: the order a walk-in continuation and the hospital's
     * own "ask more facilities" take them in.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function addShare(User $user, int $id, array $payload): array
    {
        $centreId = $this->requireFacilityId($user);
        $hospitalId = TransfusionRequest::query()->whereKey($id)->value('facility_id')
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                $share = DB::transaction(fn (): BloodRequest => $this->persistShare($user, $centreId, (int) $hospitalId, $id, $payload['lines']));

                $this->notifier->shareTakenUp($share);

                $request = $this->transfusionRepository->findForCentres($id)
                    ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

                return [
                    'message' => "{$share->reference_number} added to this center's Incoming Requests.",
                    'share' => [
                        'id' => $share->id,
                        'reference_number' => $share->reference_number,
                    ],
                    'request' => $this->row($request, $centreId),
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
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function persistShare(User $user, int $centreId, int $hospitalId, int $id, array $lines): BloodRequest
    {
        $this->bloodRequestRepository->lockFacility($hospitalId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $requirement = $this->transfusionRepository->lockById($id)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $requirement->loadMissing('hospital');

        if ($requirement->isClosed()) {
            throw $this->refuse(
                409,
                'request_closed',
                "{$requirement->reference_number} is {$requirement->status->label()} and can no longer be added to."
            );
        }

        // A hospital not on RedAgos has nobody to receive a delivery or
        // settle a bill: its blood goes out only to a watcher, at the counter.
        if ($requirement->hospital?->status === FacilityStatus::Unregistered) {
            throw $this->refuse(
                409,
                'hospital_not_registered',
                "{$requirement->hospital->name} is not on RedAgos. Record the watcher's walk-in instead."
            );
        }

        // Figures first: they reload the items, and the component names are
        // then added to those.
        $figures = $this->resolver->freshFigures($requirement);
        $requirement->loadMissing('items.component');

        [$resolved] = $this->writer->resolveShares(
            $requirement,
            [['facility_id' => $centreId, 'lines' => $this->componentLines($requirement, $lines)]],
            $figures,
            'lines'
        );

        $centreName = Facility::query()->whereKey($centreId)->value('name') ?? 'A blood centre';
        $from = $requirement->status;

        $share = $this->writer->create(
            $requirement,
            $resolved['facility'],
            $resolved['lines'],
            $user,
            RequestSource::BloodBankPortal,
            RequestEventType::Submitted,
            "{$centreName} added this share to {$requirement->reference_number}.",
        );

        $this->resolver->settle($requirement);

        $this->history->recordForRequirement(
            $requirement,
            RequestEventType::AllocationsAdded,
            $user,
            $from,
            "{$centreName} added its share: {$share->quantity} unit(s).",
            ['allocations' => [$share->reference_number]],
        );

        $this->auditLogger->record($user, 'request.share_added', $share, [
            'facility_id' => $centreId,
            'hospital_facility_id' => $requirement->facility_id,
            'reference_number' => $share->reference_number,
            'transfusion_reference_number' => $requirement->reference_number,
            'quantity' => $share->quantity,
            'components' => $share->items->pluck('component.name')->all(),
        ]);

        $share->setRelation('transfusionRequest', $requirement);

        return $share;
    }

    /**
     * Resolve each line to the requirement line it is a share of.
     *
     * The component and its indication come from the requirement, so a line
     * names only which requirement line it answers and how many units.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{component_id: int, quantity: int}>
     */
    private function componentLines(TransfusionRequest $requirement, array $lines): array
    {
        $items = $requirement->items->keyBy('id');
        $resolved = [];

        foreach (array_values($lines) as $index => $line) {
            /** @var TransfusionRequestItem|null $item */
            $item = $items->get((int) ($line['transfusion_request_item_id'] ?? 0))
                ?? throw ValidationException::withMessages([
                    "lines.{$index}.transfusion_request_item_id" => ["That component is not on {$requirement->reference_number}."],
                ]);

            $resolved[] = ['component_id' => (int) $item->component_id, 'quantity' => (int) $line['quantity']];
        }

        if (array_sum(array_column($resolved, 'quantity')) < 1) {
            throw ValidationException::withMessages([
                'lines' => ['Enter how many units this center will supply.'],
            ]);
        }

        return $resolved;
    }

    /**
     * One Blood Request as every centre may see it.
     *
     * @return array<string, mixed>
     */
    private function row(TransfusionRequest $request, int $centreId): array
    {
        $figures = $this->resolver->figures($request);
        $unallocated = (int) $figures->sum('unallocated');
        $isOpen = ! $request->isClosed();

        // Refused and withdrawn shares hold nothing, and are not shown.
        $shares = $request->facilityAllocations
            ->reject(fn (BloodRequest $share): bool => in_array($share->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true))
            ->sortBy('id')
            ->values();

        $reviewingHere = $shares->first(
            fn (BloodRequest $share): bool => (int) $share->target_facility_id === $centreId && $share->status === BloodRequestStatus::Pending
        );
        $registered = $request->hospital?->status !== FacilityStatus::Unregistered;

        $blocked = null;

        if ($isOpen && $unallocated > 0) {
            if (! $registered) {
                $blocked = 'Not on RedAgos: record the watcher\'s walk-in instead.';
            } elseif ($reviewingHere !== null) {
                $blocked = "This center is still reviewing {$reviewingHere->reference_number}.";
            }
        }

        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'hospital' => [
                'id' => $request->facility_id,
                'name' => $request->hospital?->name,
                'is_registered' => $registered,
            ],
            'patient' => ['full_name' => $request->patientFullName()],
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
            'lines' => $request->items->sortBy('id')->values()->map(fn (TransfusionRequestItem $item): array => [
                'transfusion_request_item_id' => $item->id,
                'component' => [
                    'id' => $item->component_id,
                    'name' => $item->component?->name,
                    'code' => $item->component?->labelCode(),
                ],
                'required' => (int) $item->quantity,
                'approved' => (int) ($figures->get($item->id)['approved'] ?? 0),
                'fulfilled' => (int) ($figures->get($item->id)['fulfilled'] ?? 0),
                'unallocated' => (int) ($figures->get($item->id)['unallocated'] ?? 0),
            ])->all(),
            'shares' => $shares->map(function (BloodRequest $share) use ($centreId): array {
                $mine = (int) $share->target_facility_id === $centreId;

                return [
                    // Only this centre's own share can be opened from here.
                    'id' => $mine ? $share->id : null,
                    'reference_number' => $share->reference_number,
                    'centre' => $share->targetFacility?->name,
                    'is_mine' => $mine,
                    'quantity' => (int) $share->quantity,
                    'status' => $share->status->value,
                    'status_label' => $this->transfusionProjector->allocationStatusLabel($share),
                ];
            })->all(),
            'unallocated_quantity' => $unallocated,
            'can_add_share' => $isOpen && $unallocated > 0 && $blocked === null,
            'add_share_blocked' => $blocked,
        ];
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
