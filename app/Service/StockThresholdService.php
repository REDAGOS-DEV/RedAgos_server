<?php

namespace App\Service;

use App\Enums\FacilityTypeName;
use App\Enums\StockLevel;
use App\Models\BloodComponent;
use App\Models\Facility;
use App\Models\StockThreshold;
use App\Models\User;
use App\Repository\HospitalInventoryRepository;
use App\Repository\InventoryRepository;
use App\Repository\StockThresholdRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A facility's minimum stock per blood type and component, and the alerts that follow.
 *
 * Serves blood centres and hospital blood banks through one code path; the
 * facility's type only decides whose shelf is counted. Stock is never stored
 * here. Every status is derived from the units on each read, under the Daily
 * Stock Report's rule: available, and not past its date.
 *
 * A *low episode* is per cell. It starts when the sweep first sees an
 * alerts-enabled cell below its minimum and ends when the cell is no longer
 * breached, whether because stock recovered, the minimum was lowered or
 * removed, or alerts were turned off. `alerted_at` is the mark that an episode
 * has been announced, and the only thing keeping a cell that stays low from
 * notifying every minute. It is written in the same transaction as the
 * notifications, so a failed send leaves nothing marked.
 */
class StockThresholdService
{
    public const SWEEP_SOURCE = 'schedule:inventory:check-thresholds';

    public function __construct(
        private readonly StockThresholdRepository $repository,
        private readonly InventoryRepository $inventoryRepository,
        private readonly HospitalInventoryRepository $hospitalInventoryRepository,
        private readonly StockAlertNotifier $notifier,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Every blood type and component against this facility's minimums.
     *
     * @return array<string, mixed>
     */
    public function status(User $staff): array
    {
        $facility = $this->requireFacility($staff);

        return $this->buildStatus(
            $facility,
            $this->repository->forFacility($facility->id),
            $this->issuableCounts($facility)
        );
    }

    /**
     * Set, correct or clear minimums for the caller's own facility.
     *
     * A null `minimum_units` clears the cell. Unchanged cells are skipped, so
     * a save that sends the whole grid writes and audits only what changed.
     *
     * `alerted_at` is reconciled against the counts as they stand when the
     * edit lands. A cell that is no longer breached under its new minimum, or
     * whose alerts are off, ends its episode here — otherwise lowering a
     * minimum to healthy and raising it again before the next sweep would
     * leave the old mark in place and the second shortage would never notify.
     * A cell still breached with alerts on is the same episode and keeps it.
     *
     * @param  array<int, array{blood_type_id: int, component_id: int, minimum_units: int|null, alerts_enabled?: bool|null}>  $cells
     * @return array<string, mixed>
     */
    public function save(User $staff, array $cells): array
    {
        $facility = $this->requireFacility($staff);

        DB::transaction(function () use ($staff, $facility, $cells): void {
            $active = $this->repository->activeComponentIds(array_map(
                static fn (array $cell): int => (int) $cell['component_id'],
                $cells
            ));

            foreach ($cells as $cell) {
                if (! in_array((int) $cell['component_id'], $active, true)) {
                    throw $this->refuse(422, 'component_not_found', 'That blood component is no longer available.');
                }
            }

            $existing = $this->repository->lockForFacility($facility->id);
            $counts = $this->issuableCounts($facility);

            foreach ($cells as $cell) {
                $this->saveCell($staff, $facility, $existing, $counts, $cell);
            }
        });

        return $this->status($staff);
    }

    /**
     * Announce the cells that have just fallen below their minimum, and end the episodes of those that recovered.
     *
     * Runs in one transaction that also writes the notifications, and does not
     * catch anything: if the send throws, the marks roll back with it and the
     * next sweep tries again. The caller decides what a failure means.
     *
     * @return array{alerted: int, recovered: int, notified: int}
     */
    public function sweepFacility(Facility $facility, CarbonImmutable $now, string $runId): array
    {
        return DB::transaction(function () use ($facility, $now, $runId): array {
            $thresholds = $this->repository->lockForFacility($facility->id);

            if ($thresholds->isEmpty()) {
                return ['alerted' => 0, 'recovered' => 0, 'notified' => 0];
            }

            $counts = $this->issuableCounts($facility);

            $newlyLow = [];
            $recovered = [];

            foreach ($thresholds as $key => $threshold) {
                $available = $counts[$key] ?? 0;
                $level = StockLevel::judge($available, $threshold->minimum_units);

                if ($threshold->alerts_enabled && $level->isBreach() && $threshold->alerted_at === null) {
                    $newlyLow[] = $this->item($threshold, $available, $level);
                    $threshold->forceFill(['alerted_at' => $now])->save();
                } elseif ($threshold->alerted_at !== null && (! $level->isBreach() || ! $threshold->alerts_enabled)) {
                    $recovered[] = $this->item($threshold, $available, $level) + [
                        'reason' => $level->isBreach() ? 'alerts_disabled' : 'stock_recovered',
                    ];
                    $threshold->forceFill(['alerted_at' => null])->save();
                }
            }

            if ($recovered !== []) {
                $this->auditLogger->record(null, 'stock_threshold.recovered', $facility, [
                    'facility_id' => $facility->id,
                    'items' => $recovered,
                    'source' => self::SWEEP_SOURCE,
                    'run_id' => $runId,
                ]);
            }

            $notified = 0;

            if ($newlyLow !== []) {
                $notified = $this->notifier->send($facility, $newlyLow);

                $this->auditLogger->record(null, 'stock_threshold.alerted', $facility, [
                    'facility_id' => $facility->id,
                    'items' => $newlyLow,
                    'notified_accounts' => $notified,
                    'source' => self::SWEEP_SOURCE,
                    'run_id' => $runId,
                ]);
            }

            return [
                'alerted' => count($newlyLow),
                'recovered' => count($recovered),
                'notified' => $notified,
            ];
        });
    }

    /**
     * Drop every facility's thresholds on a component that has been retired.
     *
     * A retired component disappears from the grid, so its thresholds could
     * neither be seen nor edited and would go on alerting. Restoring the
     * component does not bring them back; staff set them again.
     */
    public function retireComponent(BloodComponent $component): void
    {
        DB::transaction(function () use ($component): void {
            foreach ($this->repository->deleteForComponent($component->id) as $facilityId) {
                $this->auditLogger->record(null, 'stock_threshold.cleared', $component, [
                    'facility_id' => $facilityId,
                    'component_id' => $component->id,
                    'reason' => 'component_retired',
                ]);
            }
        });
    }

    /**
     * Facility ids the sweep has something to do for.
     *
     * @return array<int, int>
     */
    public function facilityIdsToSweep(): array
    {
        return $this->repository->facilityIdsToSweep();
    }

    /**
     * Write one cell of a save, and its audit.
     *
     * @param  Collection<string, StockThreshold>  $existing
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $cell
     */
    private function saveCell(User $staff, Facility $facility, Collection $existing, array $counts, array $cell): void
    {
        $bloodTypeId = (int) $cell['blood_type_id'];
        $componentId = (int) $cell['component_id'];
        $key = StockThresholdRepository::cellKey($bloodTypeId, $componentId);

        $before = $existing->get($key);
        $minimum = isset($cell['minimum_units']) ? (int) $cell['minimum_units'] : null;

        if ($minimum === null) {
            if ($before === null) {
                return;
            }

            $this->auditLogger->record($staff, 'stock_threshold.cleared', $before, [
                'facility_id' => $facility->id,
                'blood_type_id' => $bloodTypeId,
                'component_id' => $componentId,
                'minimum_units_before' => $before->minimum_units,
                'alerts_enabled_before' => $before->alerts_enabled,
                'episode_reset' => $before->alerted_at !== null,
                'reason' => 'cleared_by_staff',
            ]);

            $this->repository->delete($before);

            return;
        }

        $alertsEnabled = isset($cell['alerts_enabled'])
            ? (bool) $cell['alerts_enabled']
            : ($before?->alerts_enabled ?? true);

        if ($before !== null && $before->minimum_units === $minimum && $before->alerts_enabled === $alertsEnabled) {
            return;
        }

        $breached = StockLevel::judge($counts[$key] ?? 0, $minimum)->isBreach();
        $alertedAt = $before?->alerted_at !== null && $alertsEnabled && $breached ? $before->alerted_at : null;

        $saved = $this->repository->upsert($facility->id, $bloodTypeId, $componentId, [
            'minimum_units' => $minimum,
            'alerts_enabled' => $alertsEnabled,
            'alerted_at' => $alertedAt,
            // From the authenticated user, never the request body.
            'updated_by' => $staff->id,
        ]);

        $this->auditLogger->record($staff, 'stock_threshold.saved', $saved, [
            'facility_id' => $facility->id,
            'blood_type_id' => $bloodTypeId,
            'component_id' => $componentId,
            'minimum_units_before' => $before?->minimum_units,
            'minimum_units_after' => $saved->minimum_units,
            'alerts_enabled_before' => $before?->alerts_enabled,
            'alerts_enabled_after' => $saved->alerts_enabled,
            'episode_reset' => $before?->alerted_at !== null && $alertedAt === null,
        ]);
    }

    /**
     * Assemble the status payload from thresholds and counts.
     *
     * @param  Collection<string, StockThreshold>  $thresholds
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function buildStatus(Facility $facility, Collection $thresholds, array $counts): array
    {
        $bloodTypes = $this->repository->bloodTypes();
        $components = $this->repository->activeComponents();

        $cells = [];

        foreach ($bloodTypes as $bloodType) {
            foreach ($components as $component) {
                $key = StockThresholdRepository::cellKey($bloodType->id, $component->id);
                $threshold = $thresholds->get($key);
                $available = $counts[$key] ?? 0;
                $minimum = $threshold?->minimum_units;
                $level = StockLevel::judge($available, $minimum);
                $updatedBy = $threshold?->updatedBy;

                $cells[] = [
                    'blood_type_id' => $bloodType->id,
                    'blood_type_code' => $bloodType->code,
                    'component_id' => $component->id,
                    'component_name' => $component->name,
                    'available' => $available,
                    'minimum_units' => $minimum,
                    'alerts_enabled' => $threshold?->alerts_enabled ?? true,
                    'status' => $level->value,
                    'shortfall' => $minimum === null ? 0 : max(0, $minimum - $available),
                    'alerted_at' => $threshold?->alerted_at?->toIso8601String(),
                    'updated_by' => $updatedBy === null ? null : trim($updatedBy->first_name.' '.$updatedBy->last_name),
                    'updated_at' => $threshold?->updated_at?->toIso8601String(),
                ];
            }
        }

        $cells = collect($cells);

        $low = $cells
            ->filter(fn (array $cell): bool => in_array($cell['status'], [StockLevel::Low->value, StockLevel::Critical->value], true))
            ->sort(function (array $a, array $b): int {
                // Critical first, then the biggest shortfall, then a stable order.
                return [$a['status'] === StockLevel::Critical->value ? 0 : 1, -$a['shortfall'], $a['blood_type_id'], $a['component_name']]
                    <=> [$b['status'] === StockLevel::Critical->value ? 0 : 1, -$b['shortfall'], $b['blood_type_id'], $b['component_name']];
            })
            ->values()
            ->all();

        return [
            'facility' => [
                'id' => $facility->id,
                'name' => $facility->name,
                'type' => $this->typeOf($facility)->value,
            ],
            'blood_types' => $bloodTypes->map(fn ($type): array => ['id' => $type->id, 'code' => $type->code])->all(),
            'components' => $components->map(fn (BloodComponent $component): array => [
                'id' => $component->id,
                'name' => $component->name,
                'code' => $component->labelCode(),
            ])->all(),
            'cells' => $cells->all(),
            'low' => $low,
            'totals' => [
                'monitored' => $cells->where('status', '!=', StockLevel::Unmonitored->value)->count(),
                'ok' => $cells->where('status', StockLevel::Ok->value)->count(),
                'low' => $cells->where('status', StockLevel::Low->value)->count(),
                'critical' => $cells->where('status', StockLevel::Critical->value)->count(),
            ],
            'as_of' => now()->toIso8601String(),
        ];
    }

    /**
     * Issuable units per cell, from whichever shelf this facility keeps.
     *
     * @return array<string, int>
     */
    private function issuableCounts(Facility $facility): array
    {
        $today = OperationalDay::todayAsDate();

        $rows = $this->typeOf($facility) === FacilityTypeName::BloodBank
            ? $this->hospitalInventoryRepository->issuableCountsByTypeAndComponent($facility->id, $today)
            : $this->inventoryRepository->issuableCountsByTypeAndComponent($facility->id, $today);

        $counts = [];

        foreach ($rows as $row) {
            $counts[StockThresholdRepository::cellKey($row['blood_type_id'], $row['component_id'])] = $row['units'];
        }

        return $counts;
    }

    /**
     * One cell as it appears in a notification or an audit row.
     *
     * @return array{blood_type_id: int, blood_type_code: string, component_id: int, component_name: string, available: int, minimum_units: int, status: string}
     */
    private function item(StockThreshold $threshold, int $available, StockLevel $level): array
    {
        return [
            'blood_type_id' => $threshold->blood_type_id,
            'blood_type_code' => (string) $threshold->bloodType?->code,
            'component_id' => $threshold->component_id,
            'component_name' => (string) $threshold->component?->name,
            'available' => $available,
            'minimum_units' => $threshold->minimum_units,
            'status' => $level->value,
        ];
    }

    private function typeOf(Facility $facility): FacilityTypeName
    {
        $facility->loadMissing('facilityType');

        return FacilityTypeName::tryFrom((string) $facility->facilityType?->name) ?? FacilityTypeName::BloodCenter;
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
        ], $status));
    }
}
