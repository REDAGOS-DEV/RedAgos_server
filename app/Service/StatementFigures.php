<?php

namespace App\Service;

use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Repository\BloodComponentRepository;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What a statement asks for and what it has collected, worked out from the records.
 *
 * Shared by BillingService, which keeps the live statement, and
 * StatementRevisionService, which freezes it into an issued Statement of
 * Account, so the two can never price the same request differently. Every
 * figure is in centavos.
 */
class StatementFigures
{
    public function __construct(
        private readonly BloodComponentRepository $bloodComponentRepository
    ) {}

    /**
     * The request's billable lines: each component with units held, at the price it was billed at.
     *
     * Held means claimed — reserved or released — because a statement covers
     * what the centre has committed to the request. Priced by the facility
     * fulfilling the request (target_facility_id), never the hospital that
     * asked; an unset price is zero. A line with nothing held is left out.
     *
     * A line keeps the price it was first billed at (lockPrices()), so a
     * centre changing its price leaves every bill already raised as it was
     * (project owner, 2026-11-26). Only a line not billed yet reads the
     * centre's present price.
     *
     * On a weekly request whose lines differ in blood type, each line is named
     * with its type, so A+ and O+ packed cells are two lines a reader can tell
     * apart rather than one component listed twice.
     *
     * @return array<int, array{request_item_id: int, component_id: int, component_name: string, quantity: int, unit_price: int, line_total: int}>
     */
    public function linesFor(BloodRequest $request): array
    {
        $request->loadMissing(['items.component', 'items.bloodType']);
        $mixed = $request->blood_type_id === null;

        $held = $this->heldPerLine($request);

        $lines = [];

        foreach ($request->items as $item) {
            $quantity = (int) ($held[$item->id] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $unitPrice = $item->billed_unit_price !== null
                ? Money::toCentavos($item->billed_unit_price)
                : $this->unitPriceFor($request, (int) $item->component_id);

            $lines[] = [
                'request_item_id' => (int) $item->id,
                'component_id' => (int) $item->component_id,
                'component_name' => trim(
                    ($mixed ? ($item->bloodType?->code ?? '').' ' : '').($item->component?->name ?? 'Component')
                ),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $unitPrice * $quantity,
            ];
        }

        return $lines;
    }

    /**
     * Lock the price of every line going on the bill for the first time.
     *
     * The centre's present price is copied onto each line that has units held
     * and no price yet; a line that has one keeps it. The bill is a record of
     * what was charged when it was raised, so a price changed afterwards
     * applies only to lines billed after the change (project owner,
     * 2026-11-26). Units tagged later on a line already billed go on at that
     * line's price, as does a unit swapped for another.
     *
     * Called by BillingService::syncFor() on every tagging and dispatch, the
     * only ways a line comes onto a bill, before the bill reads its lines.
     * Must run inside a transaction that holds the request lock.
     */
    public function lockPrices(BloodRequest $request): void
    {
        $request->loadMissing('items');
        $held = $this->heldPerLine($request);

        foreach ($request->items as $item) {
            if ($item->billed_unit_price !== null || (int) ($held[$item->id] ?? 0) < 1) {
                continue;
            }

            $item->forceFill([
                'billed_unit_price' => Money::toDecimal($this->unitPriceFor($request, (int) $item->component_id)),
                'priced_at' => now(),
            ])->save();
        }
    }

    /**
     * The live lines as a billing screen shows them: decimal strings, each with its blood type.
     *
     * The component is named plainly here, since the blood type has a column of
     * its own; the issued document keeps the combined name linesFor() gives it.
     *
     * @return array<int, array{component_name: string, blood_type: string|null, quantity: int, unit_price: string, line_total: string}>
     */
    public function displayLinesFor(BloodRequest $request): array
    {
        $request->loadMissing(['items.component', 'items.bloodType', 'bloodType']);
        $items = $request->items->keyBy('id');

        return array_map(function (array $line) use ($items, $request): array {
            $item = $items->get($line['request_item_id']);

            return [
                'component_name' => $item?->component?->name ?? $line['component_name'],
                'blood_type' => $item?->bloodType?->code ?? $request->bloodType?->code,
                'quantity' => $line['quantity'],
                'unit_price' => Money::toDecimal($line['unit_price']),
                'line_total' => Money::toDecimal($line['line_total']),
            ];
        }, $this->linesFor($request));
    }

    /**
     * The total of a set of lines.
     *
     * @param  array<int, array{line_total: int}>  $lines
     */
    public function totalOf(array $lines): int
    {
        return array_sum(array_column($lines, 'line_total'));
    }

    /**
     * How many units a set of lines holds.
     *
     * @param  array<int, array{quantity: int}>  $lines
     */
    public function unitsOf(array $lines): int
    {
        return array_sum(array_column($lines, 'quantity'));
    }

    /**
     * Sum what a statement has actually collected.
     *
     * Only completed payments count. A failed or refunded attempt is part of
     * the trail, never part of the total. Added up row by row rather than with
     * SQL SUM, which SQLite returns as a float: each stored decimal becomes
     * exact centavos first.
     */
    public function collectedFor(Billing $billing): int
    {
        return (int) $billing->payments()
            ->where('status', PaymentStatus::Completed)
            ->pluck('amount_paid')
            ->sum(fn (int|float|string $amount): int => Money::toCentavos($amount));
    }

    /**
     * How many units each of the request's lines holds, keyed by line.
     *
     * @return Collection<int, int>
     */
    private function heldPerLine(BloodRequest $request): Collection
    {
        return $request->allocations()->claiming()
            ->groupBy('request_item_id')
            ->selectRaw('request_item_id, COUNT(*) as held')
            ->pluck('held', 'request_item_id');
    }

    /**
     * One component's present unit price at the facility fulfilling the request.
     */
    private function unitPriceFor(BloodRequest $request, int $componentId): int
    {
        if ($request->target_facility_id === null) {
            return 0;
        }

        $price = $this->bloodComponentRepository
            ->setting((int) $request->target_facility_id, $componentId)
            ?->price;

        return Money::toCentavos($price ?? 0);
    }
}
