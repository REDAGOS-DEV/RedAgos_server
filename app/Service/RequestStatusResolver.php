<?php

namespace App\Service;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\LineFulfilmentStatus;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\RequestAllocation;
use Illuminate\Support\Collection;

/**
 * The one place one request's figures and status are derived.
 *
 * A request is what was asked for; its fulfilment is what was provided. The two
 * are stored apart — the lines hold the requested quantities and never change,
 * the allocations hold every unit reserved, released and received — and
 * everything a screen shows about progress is read from them here. Before this
 * class the status was written from three services, each with its own idea of
 * what it meant, and a top-up on a partly filled request knocked it back to
 * `processing`.
 *
 * Fulfilment is counted at dispatch: a unit is fulfilled when it has been
 * released. Receipt is still stamped per unit by the hospital and reported as
 * a figure, but it no longer moves the status.
 *
 * For a Patient Transfusion this is one facility allocation.
 * TransfusionRequestResolver adds the allocations' figures up into the
 * patient's requirement.
 */
class RequestStatusResolver
{
    /**
     * The relations figures() reads. settle() reloads them fresh.
     *
     * @var array<int, string>
     */
    public const RELATIONS = [
        'items',
        'allocations',
    ];

    /**
     * Work out every line's figures from the request's loaded relations.
     *
     * Pure: it reads what the caller loaded and queries nothing, so a listing
     * can call it for every row without a query per row. Load RELATIONS first.
     *
     * An allocation that names no line predates multi-component requests and
     * is counted against the first line, which is the line it was backfilled
     * to answer.
     *
     * @return Collection<int, array<string, mixed>> keyed by line id, in form order
     */
    public function figures(BloodRequest $request): Collection
    {
        $items = $request->items->sortBy('id')->values();
        $firstLineId = $items->first()?->id;

        $claimed = $request->allocations
            ->filter(fn (RequestAllocation $allocation): bool => $allocation->status->claimsUnit())
            ->groupBy(fn (RequestAllocation $allocation): int => (int) ($allocation->request_item_id ?? $firstLineId));

        // A refused or withdrawn request supplies nothing further.
        $finished = in_array($request->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true);

        return $items->mapWithKeys(function (BloodRequestItem $item) use ($claimed, $finished): array {
            $held = $claimed->get($item->id, collect());

            $released = $held->filter(fn (RequestAllocation $allocation): bool => $allocation->status === AllocationStatus::Released);

            $requested = (int) $item->quantity;
            $reserved = $held->filter(fn (RequestAllocation $allocation): bool => $allocation->status === AllocationStatus::Allocated)->count();
            $fulfilled = $released->count();
            $received = $released->filter(fn (RequestAllocation $allocation): bool => $allocation->received_at !== null)->count();
            $closed = $item->closed_at !== null;

            $open = max(0, $requested - $reserved - $fulfilled);

            return [$item->id => [
                'request_item_id' => $item->id,
                'transfusion_request_item_id' => $item->transfusion_request_item_id,
                'component_id' => $item->component_id,
                'requested' => $requested,
                'reserved' => $reserved,
                'fulfilled' => $fulfilled,
                'received' => $received,
                'remaining' => max(0, $requested - $fulfilled),
                // What this facility may still hold for the line: nothing once
                // the line is closed or the request refused or withdrawn.
                'allocatable' => ($finished || $closed) ? 0 : $open,
                'closed' => $closed,
                'resolved' => $this->isResolved($requested, $reserved, $fulfilled, $closed),
                'status' => $this->lineStatus($requested, $reserved, $fulfilled, $closed),
            ]];
        });
    }

    /**
     * Load fresh figures for a request, for a write that must see its own changes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function freshFigures(BloodRequest $request): Collection
    {
        $request->load(self::RELATIONS);

        return $this->figures($request);
    }

    /**
     * Move the request to whatever its fulfilment now justifies, and save it.
     *
     * Rejected and cancelled are decisions, never derived, and are left alone.
     * Everything else follows from the lines:
     *
     *  - every line resolved and every line fully released: Fulfilled;
     *  - every line resolved but some closed short: Partial and closed, which
     *    is terminal;
     *  - anything released: Partial, still open to top-ups;
     *  - anything reserved: Processing;
     *  - otherwise Pending.
     *
     * Must be called inside the transaction that made the change, with the
     * request row locked.
     */
    public function settle(BloodRequest $request): BloodRequestStatus
    {
        if (in_array($request->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true)) {
            return $request->status;
        }

        ['status' => $status, 'closed' => $closed] = $this->derive($request);

        $request->status = $status;

        if ($status === BloodRequestStatus::Fulfilled) {
            $request->fulfilled_at ??= now();
        } else {
            $request->fulfilled_at = null;
        }

        $request->closed_at = $closed ? ($request->closed_at ?? now()) : null;

        $request->save();

        return $request->status;
    }

    /**
     * Work out what the request's status should be, without saving anything.
     *
     * Reads the lines fresh. Rejected and cancelled requests are returned as
     * they stand, since those are decisions rather than derivations.
     *
     * @return array{status: BloodRequestStatus, closed: bool}
     */
    public function derive(BloodRequest $request): array
    {
        if (in_array($request->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true)) {
            return ['status' => $request->status, 'closed' => true];
        }

        $lines = $this->freshFigures($request);

        $allResolved = $lines->isNotEmpty() && $lines->every(fn (array $line): bool => $line['resolved']);
        $fullyHere = $lines->isNotEmpty() && $lines->every(fn (array $line): bool => $line['fulfilled'] >= $line['requested']);
        $anyFulfilled = $lines->sum('fulfilled') > 0;
        $anyReserved = $lines->sum('reserved') > 0;

        if ($allResolved && $anyFulfilled) {
            return [
                'status' => $fullyHere ? BloodRequestStatus::Fulfilled : BloodRequestStatus::Partial,
                'closed' => true,
            ];
        }

        return [
            'status' => match (true) {
                $anyFulfilled => BloodRequestStatus::Partial,
                $anyReserved => BloodRequestStatus::Processing,
                default => BloodRequestStatus::Pending,
            },
            'closed' => false,
        ];
    }

    /**
     * Whether closing the given lines would leave a request with nothing supplied.
     *
     * Every closing path asks this before it acts. A request whose lines are
     * all closed without a single unit released or reserved is not partially
     * fulfilled — it was never fulfilled at all, and the honest ending for that
     * is a rejection or a cancellation.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  array<int, int>  $closing  line ids about to be closed
     */
    public function wouldEmpty(Collection $lines, array $closing = []): bool
    {
        if ($lines->sum('fulfilled') + $lines->sum('reserved') > 0) {
            return false;
        }

        return $lines->every(function (array $line) use ($closing): bool {
            $closed = $line['closed'] || in_array($line['request_item_id'], $closing, true);

            return $this->isResolved($line['requested'], $line['reserved'], $line['fulfilled'], $closed);
        });
    }

    /**
     * A line is resolved once nothing more is expected of this facility for it.
     *
     * A closed line with units still reserved is not resolved: those units are
     * still to be released or returned.
     */
    private function isResolved(int $requested, int $reserved, int $fulfilled, bool $closed): bool
    {
        return $fulfilled >= $requested || ($closed && $reserved === 0);
    }

    private function lineStatus(int $requested, int $reserved, int $fulfilled, bool $closed): LineFulfilmentStatus
    {
        return match (true) {
            $fulfilled >= $requested => LineFulfilmentStatus::Fulfilled,
            $closed && $reserved === 0 => LineFulfilmentStatus::ClosedShort,
            $fulfilled > 0 => LineFulfilmentStatus::Partial,
            default => LineFulfilmentStatus::Unfulfilled,
        };
    }
}
