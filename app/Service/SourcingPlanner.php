<?php

namespace App\Service;

use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Repository\AvailabilityRepository;
use App\Support\OperationalDay;
use Illuminate\Support\Collection;

/**
 * Suggests how to split a patient's requirement across the network.
 *
 * For each component it lists the blood centres holding matching issuable
 * stock and fills a suggested quantity from them, earliest expiry first —
 * the FEFO rule the proposal sets to keep blood from being wasted — until the
 * quantity is covered. Centres holding none are listed too: a hospital may
 * still ask one for tomorrow.
 *
 * Advisory in exactly the way an availability search is. Nothing here holds a
 * unit; a centre reserves real bags only when it approves its allocation, and
 * re-checks its shelf under a lock when it does.
 */
class SourcingPlanner
{
    public function __construct(
        private readonly AvailabilityRepository $availabilityRepository
    ) {}

    /**
     * Plan every component of a requirement.
     *
     * @param  array<int, array{component_id: int, quantity: int, transfusion_request_item_id?: int|null}>  $lines
     * @return array<string, mixed>
     */
    public function plan(int $hospitalId, int $bloodTypeId, array $lines): array
    {
        $today = OperationalDay::todayAsDate();
        $bloodType = BloodType::query()->findOrFail($bloodTypeId);
        $components = BloodComponent::query()
            ->whereIn('id', array_column($lines, 'component_id'))
            ->pluck('name', 'id');

        $eligible = $this->availabilityRepository->eligibleTargets($hospitalId);

        return [
            'blood_type' => ['id' => $bloodType->id, 'code' => $bloodType->code],
            'lines' => array_map(
                fn (array $line): array => $this->planLine($hospitalId, $bloodTypeId, $line, $components->get($line['component_id']), $eligible, $today),
                $lines
            ),
            'advisory' => true,
            'as_of' => OperationalDay::today()->toIso8601String(),
        ];
    }

    /**
     * @param  array{component_id: int, quantity: int, transfusion_request_item_id?: int|null}  $line
     * @param  Collection<int, Facility>  $eligible
     * @return array<string, mixed>
     */
    private function planLine(int $hospitalId, int $bloodTypeId, array $line, ?string $componentName, $eligible, string $today): array
    {
        $wanted = max(0, (int) $line['quantity']);

        $holdings = $this->availabilityRepository
            ->countsByFacility($bloodTypeId, (int) $line['component_id'], $today, $hospitalId)
            ->map(fn (object $row): array => [
                'facility' => [
                    'id' => (int) $row->facility_id,
                    'name' => $row->facility_name,
                    'address' => $row->address,
                ],
                'available' => (int) $row->available,
                'earliest_expiry' => $row->earliest_expiry,
            ])
            // Earliest expiry first, then the deepest shelf, then by name so
            // the order is stable between two identical centres.
            ->sort(fn (array $a, array $b): int => [$a['earliest_expiry'] ?? '9999-12-31', -$a['available'], $a['facility']['name']]
                <=> [$b['earliest_expiry'] ?? '9999-12-31', -$b['available'], $b['facility']['name']])
            ->values();

        $left = $wanted;

        $facilities = $holdings->map(function (array $holding) use (&$left): array {
            $suggested = min($holding['available'], $left);
            $left -= $suggested;

            return [...$holding, 'suggested' => $suggested];
        })->all();

        $stocked = array_column(array_column($facilities, 'facility'), 'id');

        return [
            'transfusion_request_item_id' => $line['transfusion_request_item_id'] ?? null,
            'component' => ['id' => (int) $line['component_id'], 'name' => $componentName],
            'quantity' => $wanted,
            'facilities' => $facilities,
            'other_facilities' => $eligible
                ->reject(fn (Facility $facility): bool => in_array($facility->id, $stocked, true))
                ->map(fn (Facility $facility): array => [
                    'id' => $facility->id,
                    'name' => $facility->name,
                    'address' => $facility->address,
                ])
                ->values()
                ->all(),
            'suggested_total' => $wanted - $left,
            'shortfall' => $left,
        ];
    }
}
