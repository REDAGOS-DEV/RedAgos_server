<?php

namespace App\Service;

use App\Enums\BloodUnitStatus;
use App\Models\BloodRequestItem;
use App\Models\BloodUnit;
use App\Repository\InventoryRepository;
use App\Support\OperationalDay;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Match the bags staff picked from the shelf to the lines of a request.
 *
 * The one judgement of a hand-pick, shared by the two times staff pick: tagging
 * units for a patient's request (RequestAllocationService::allocate) and
 * dispatching a replenishment order (FulfillmentService::dispatchOrder).
 *
 * Refused whole, with a reason against each unit that cannot be taken: a partial
 * take of a hand-picked set would leave staff guessing which bags went where.
 */
class ChosenUnitsMatcher
{
    public function __construct(
        private readonly InventoryRepository $inventoryRepository
    ) {}

    /**
     * Lock the picked units and put each against a line with room left for it.
     *
     * Each must be one this centre could issue now — its own, available, in
     * date, claimed by nobody — of a line's blood type and component.
     *
     * @param  Collection<int, BloodRequestItem>  $lines
     * @param  Collection<int, array<string, mixed>>  $figures
     * @param  array<int, string>  $unitIds
     * @return array<int, array{line: BloodRequestItem, units: array<int, BloodUnit>}> keyed by line id
     */
    public function match(Collection $lines, Collection $figures, array $unitIds, int $facilityId): array
    {
        $lines->each(fn (BloodRequestItem $line) => $line->loadMissing(['component', 'bloodType']));

        $today = OperationalDay::todayAsDate();
        $units = $this->inventoryRepository->lockUnitsById($facilityId, $unitIds);
        $claimed = array_flip($this->inventoryRepository->claimedUnitIds($unitIds));

        $room = [];

        foreach ($lines as $line) {
            $room[$line->id] = (int) ($figures->get($line->id)['allocatable'] ?? 0);
        }

        $chosen = [];
        $errors = [];

        foreach (array_values($unitIds) as $index => $unitId) {
            $unit = $units->get($unitId);
            $field = "unit_ids.{$index}";

            if ($unit === null) {
                $errors[$field] = ["{$unitId} is not in this centre's stock."];

                continue;
            }

            if ($unit->status !== BloodUnitStatus::Available || isset($claimed[$unitId]) || $unit->expiry_date->toDateString() < $today) {
                $errors[$field] = ["{$unitId} is no longer available."];

                continue;
            }

            // The line's own blood type and component, never a compatible
            // substitute.
            $matching = $lines->filter(fn (BloodRequestItem $line): bool => (int) $line->blood_type_id === (int) $unit->blood_type_id
                && (int) $line->component_id === (int) $unit->component_id);

            if ($matching->isEmpty()) {
                $errors[$field] = ["{$unitId} is not a blood type and component this request asks for."];

                continue;
            }

            $line = $matching->first(fn (BloodRequestItem $candidate): bool => $room[$candidate->id] > 0);

            if ($line === null) {
                $first = $matching->first();
                $errors[$field] = [$first->committed_quantity === null
                    ? "More {$first->component?->name} ({$first->bloodType?->code}) was picked than this request still needs."
                    : "More {$first->component?->name} ({$first->bloodType?->code}) was picked than you committed to supply."];

                continue;
            }

            $room[$line->id]--;
            $chosen[$line->id]['line'] = $line;
            $chosen[$line->id]['units'][] = $unit;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $chosen;
    }
}
