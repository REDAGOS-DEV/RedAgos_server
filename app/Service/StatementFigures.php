<?php

namespace App\Service;

use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Repository\BloodComponentRepository;
use App\Support\Money;

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
     * The request's billable lines: each component with units held, priced at the fulfilling facility's rate.
     *
     * Held means claimed — reserved or released — because a statement covers
     * what the centre has committed to the request. Priced by the facility
     * fulfilling the request (target_facility_id), never the hospital that
     * asked; an unset price is zero. A line with nothing held is left out.
     *
     * @return array<int, array{request_item_id: int, component_id: int, component_name: string, quantity: int, unit_price: int, line_total: int}>
     */
    public function linesFor(BloodRequest $request): array
    {
        $request->loadMissing('items.component');

        $held = $request->allocations()->claiming()
            ->groupBy('request_item_id')
            ->selectRaw('request_item_id, COUNT(*) as held')
            ->pluck('held', 'request_item_id');

        $lines = [];

        foreach ($request->items as $item) {
            $quantity = (int) ($held[$item->id] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $unitPrice = $this->unitPriceFor($request, (int) $item->component_id);

            $lines[] = [
                'request_item_id' => (int) $item->id,
                'component_id' => (int) $item->component_id,
                'component_name' => $item->component?->name ?? 'Component',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $unitPrice * $quantity,
            ];
        }

        return $lines;
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
     * One component's unit price at the facility fulfilling the request.
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
