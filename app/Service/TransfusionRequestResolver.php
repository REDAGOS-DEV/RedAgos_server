<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\TransfusionLineStatus;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use App\Models\TransfusionRequestItem;
use App\Repository\TransfusionRequestRepository;
use Illuminate\Support\Collection;

/**
 * The one place a patient's requirement is added up and its status derived.
 *
 * The requirement stores only what the patient needs. Everything else is read
 * from its facility allocations, each of which RequestStatusResolver already
 * knows how to read: what was asked of a centre, what it is still to answer,
 * what it holds for the patient, released, and the hospital received.
 *
 * Refused and withdrawn allocations contribute nothing, and neither does a
 * remainder a centre closed as unavailable: those quantities flow straight
 * back into "unallocated", so asking another facility always offers exactly
 * the units nobody is holding or still considering.
 */
class TransfusionRequestResolver
{
    public function __construct(
        private readonly RequestStatusResolver $allocationResolver,
        private readonly TransfusionRequestRepository $repository
    ) {}

    /**
     * Add up every requirement line from the requirement's loaded allocations.
     *
     * Pure, like RequestStatusResolver::figures(): it reads what the caller
     * loaded. Load TransfusionRequestRepository::FIGURE_RELATIONS first.
     *
     * @return Collection<int, array<string, mixed>> keyed by requirement line id
     */
    public function figures(TransfusionRequest $request): Collection
    {
        $cancelled = $request->status === BloodRequestStatus::Cancelled;
        $perLine = [];

        foreach ($request->facilityAllocations as $allocation) {
            if (in_array($allocation->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true)) {
                continue;
            }

            foreach ($this->allocationResolver->figures($allocation) as $line) {
                if ($line['transfusion_request_item_id'] !== null) {
                    $perLine[$line['transfusion_request_item_id']][] = $line;
                }
            }
        }

        return $request->items->sortBy('id')->values()->mapWithKeys(
            function (TransfusionRequestItem $item) use ($perLine, $cancelled): array {
                $lines = collect($perLine[$item->id] ?? []);

                $required = (int) $item->quantity;
                $reserved = (int) $lines->sum('reserved');
                $fulfilled = (int) $lines->sum('fulfilled');
                $approved = $reserved + $fulfilled;
                $awaiting = $cancelled ? 0 : (int) $lines->sum('allocatable');
                $closed = $item->closed_at !== null;

                return [$item->id => [
                    'transfusion_request_item_id' => $item->id,
                    'component_id' => $item->component_id,
                    'required' => $required,
                    'requested' => (int) $lines->sum('requested'),
                    'awaiting' => $awaiting,
                    'reserved' => $reserved,
                    'approved' => $approved,
                    'fulfilled' => $fulfilled,
                    'received' => (int) $lines->sum('received'),
                    'remaining' => max(0, $required - $approved),
                    'unallocated' => ($closed || $cancelled) ? 0 : max(0, $required - $approved - $awaiting),
                    'closed' => $closed,
                    'resolved' => $fulfilled >= $required || ($closed && $awaiting === 0 && $reserved === 0),
                    'status' => $this->lineStatus($required, $awaiting, $reserved, $approved, $fulfilled, $closed),
                ]];
            }
        );
    }

    /**
     * Load fresh figures, for a write that must see its own changes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function freshFigures(TransfusionRequest $request): Collection
    {
        $request->load(TransfusionRequestRepository::FIGURE_RELATIONS);

        return $this->figures($request);
    }

    /**
     * Move the requirement to whatever its allocations now justify, and save it.
     *
     * Cancelled is the hospital's decision and is left alone. Otherwise:
     *
     *  - every line fully released: Fulfilled;
     *  - every line resolved but some short — closed as no longer needed:
     *    Partial and closed, which is terminal;
     *  - anything released: Partial, still open;
     *  - anything approved (reserved): Processing;
     *  - otherwise Pending, awaiting the facilities.
     *
     * Call inside the transaction that made the change, with the requirement
     * row locked.
     */
    public function settle(TransfusionRequest $request): BloodRequestStatus
    {
        if ($request->status === BloodRequestStatus::Cancelled) {
            return $request->status;
        }

        ['status' => $status, 'closed' => $closed] = $this->derive($request);

        $request->status = $status;
        $request->fulfilled_at = $status === BloodRequestStatus::Fulfilled ? ($request->fulfilled_at ?? now()) : null;
        $request->closed_at = $closed ? ($request->closed_at ?? now()) : null;
        $request->save();

        return $request->status;
    }

    /**
     * Work out what the requirement's status should be, without saving anything.
     *
     * @return array{status: BloodRequestStatus, closed: bool}
     */
    public function derive(TransfusionRequest $request): array
    {
        if ($request->status === BloodRequestStatus::Cancelled) {
            return ['status' => $request->status, 'closed' => true];
        }

        $lines = $this->freshFigures($request);

        $allResolved = $lines->isNotEmpty() && $lines->every(fn (array $line): bool => $line['resolved']);
        $allFulfilled = $lines->isNotEmpty() && $lines->every(fn (array $line): bool => $line['fulfilled'] >= $line['required']);
        $anyFulfilled = $lines->sum('fulfilled') > 0;
        $anyApproved = $lines->sum('approved') > 0;

        if ($allResolved && $anyFulfilled) {
            return [
                'status' => $allFulfilled ? BloodRequestStatus::Fulfilled : BloodRequestStatus::Partial,
                'closed' => true,
            ];
        }

        return [
            'status' => match (true) {
                $anyFulfilled => BloodRequestStatus::Partial,
                $anyApproved => BloodRequestStatus::Processing,
                default => BloodRequestStatus::Pending,
            },
            'closed' => false,
        ];
    }

    /**
     * Re-derive the requirement a facility allocation belongs to.
     *
     * The hook every allocation write calls after changing the allocation, with
     * the allocation already locked. The requirement is locked after it — the
     * order every path takes them in — so this can never deadlock against a
     * write on the requirement itself. A replenishment request has no
     * requirement, and this does nothing.
     */
    public function settleParentOf(BloodRequest $allocation): ?TransfusionRequest
    {
        if ($allocation->transfusion_request_id === null) {
            return null;
        }

        $request = $this->repository->lockById((int) $allocation->transfusion_request_id);

        if ($request === null) {
            return null;
        }

        $this->settle($request);

        return $request;
    }

    /**
     * Whether closing the given lines would end a requirement nobody supplied anything for.
     *
     * A requirement with nothing approved anywhere, whose every line would be
     * closed, was never fulfilled at all — cancelling it says so honestly.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  array<int, int>  $closing  requirement line ids about to be closed
     */
    public function wouldEmpty(Collection $lines, array $closing): bool
    {
        if ($lines->sum('approved') > 0) {
            return false;
        }

        return $lines->every(
            fn (array $line): bool => $line['closed'] || in_array($line['transfusion_request_item_id'], $closing, true)
        );
    }

    /**
     * Whether any units are asked of nobody: waiting on the hospital, not on a centre.
     *
     * True for a shortfall submitted from the start as much as for a share a
     * centre refused, so the hospital is shown every unit nobody is holding or
     * still considering.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    public function needsAllocation(Collection $lines): bool
    {
        return $lines->contains(fn (array $line): bool => $line['unallocated'] > 0);
    }

    private function lineStatus(int $required, int $awaiting, int $reserved, int $approved, int $fulfilled, bool $closed): TransfusionLineStatus
    {
        return match (true) {
            $fulfilled >= $required => TransfusionLineStatus::Fulfilled,
            $closed && $awaiting === 0 && $reserved === 0 => TransfusionLineStatus::ClosedShort,
            $fulfilled > 0 => TransfusionLineStatus::PartiallyFulfilled,
            $approved >= $required => TransfusionLineStatus::Approved,
            $approved > 0 => TransfusionLineStatus::PartiallyApproved,
            $awaiting > 0 => TransfusionLineStatus::AwaitingResponse,
            default => TransfusionLineStatus::NeedsAllocation,
        };
    }
}
