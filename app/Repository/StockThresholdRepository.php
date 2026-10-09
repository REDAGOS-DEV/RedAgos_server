<?php

namespace App\Repository;

use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\StockThreshold;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Every read and write of facilities' stock thresholds.
 *
 * Reads that feed a status or a sweep only see thresholds on components that
 * have not been retired, so a row left behind on one can never alert from a
 * cell no screen can show or edit.
 */
class StockThresholdRepository
{
    /**
     * The key a threshold, a count and a payload cell share.
     */
    public static function cellKey(int $bloodTypeId, int $componentId): string
    {
        return "{$bloodTypeId}:{$componentId}";
    }

    /**
     * One facility's thresholds on active components, keyed by cell.
     *
     * @return SupportCollection<string, StockThreshold>
     */
    public function forFacility(int $facilityId): SupportCollection
    {
        return StockThreshold::query()
            ->forFacility($facilityId)
            ->onActiveComponents()
            ->with('updatedBy:id,first_name,last_name')
            ->get()
            ->keyBy(fn (StockThreshold $threshold): string => self::cellKey($threshold->blood_type_id, $threshold->component_id));
    }

    /**
     * One facility's thresholds on active components, locked for the transaction.
     *
     * Both a save and the sweep take this lock, in id order, so they serialise
     * on the facility's rows: a sweep cannot mark a cell alerted from counts a
     * concurrent save has just made stale, and the reverse.
     *
     * @return SupportCollection<string, StockThreshold>
     */
    public function lockForFacility(int $facilityId): SupportCollection
    {
        return StockThreshold::query()
            ->forFacility($facilityId)
            ->onActiveComponents()
            ->with(['bloodType:id,code', 'component:id,name'])
            ->orderBy('stock_thresholds.id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (StockThreshold $threshold): string => self::cellKey($threshold->blood_type_id, $threshold->component_id));
    }

    /**
     * Create or correct one cell's threshold.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(int $facilityId, int $bloodTypeId, int $componentId, array $attributes): StockThreshold
    {
        $threshold = StockThreshold::query()->updateOrCreate(
            ['facility_id' => $facilityId, 'blood_type_id' => $bloodTypeId, 'component_id' => $componentId],
            $attributes
        );

        return $threshold->refresh();
    }

    public function delete(StockThreshold $threshold): void
    {
        $threshold->delete();
    }

    /**
     * Delete every facility's thresholds on a component, and say whose they were.
     *
     * @return array<int, int> The facility ids that had one.
     */
    public function deleteForComponent(int $componentId): array
    {
        $facilityIds = StockThreshold::query()
            ->where('component_id', $componentId)
            ->pluck('facility_id')
            ->unique()
            ->values()
            ->all();

        StockThreshold::query()->where('component_id', $componentId)->delete();

        return array_map('intval', $facilityIds);
    }

    /**
     * Facilities with something for the sweep to do.
     *
     * A threshold with alerts on can start an episode, and one already alerted
     * can end it, whether or not alerts are still on. Anything else is inert.
     *
     * @return array<int, int>
     */
    public function facilityIdsToSweep(): array
    {
        return StockThreshold::query()
            ->onActiveComponents()
            ->where(function ($query): void {
                $query->where('alerts_enabled', true)->orWhereNotNull('alerted_at');
            })
            ->distinct()
            ->orderBy('facility_id')
            ->pluck('facility_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Every blood type, in id order.
     *
     * @return Collection<int, BloodType>
     */
    public function bloodTypes(): Collection
    {
        return BloodType::query()->orderBy('id')->get();
    }

    /**
     * Every component that has not been retired, by name.
     *
     * @return Collection<int, BloodComponent>
     */
    public function activeComponents(): Collection
    {
        return BloodComponent::query()->orderBy('name')->get();
    }

    /**
     * The ids among those given that belong to active components.
     *
     * @param  array<int, int>  $componentIds
     * @return array<int, int>
     */
    public function activeComponentIds(array $componentIds): array
    {
        return BloodComponent::query()
            ->whereIn('id', $componentIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
