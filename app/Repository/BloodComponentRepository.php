<?php

namespace App\Repository;

use App\Models\BloodComponent;
use App\Models\FacilityBloodComponent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The component catalogue, and each facility's own settings over it.
 *
 * `blood_components` is one shared list of names with no facility_id. Shelf
 * life and price are not shared, so they live in `facility_blood_components`
 * and are read through here keyed by facility.
 */
class BloodComponentRepository
{
    /**
     * @return Collection<int, BloodComponent>
     */
    public function all(): Collection
    {
        return BloodComponent::query()->orderBy('name')->get();
    }

    public function find(int $componentId): ?BloodComponent
    {
        return BloodComponent::query()->whereKey($componentId)->first();
    }

    /**
     * Add a component to the shared catalogue.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): BloodComponent
    {
        return BloodComponent::query()->create($attributes);
    }

    /**
     * Whether a component, retired ones included, already has this name or code.
     *
     * Case-insensitive, so "packed rbc" is not added beside "Packed RBC". A
     * retired component keeps its row, and the unique index still covers it,
     * so its name and code stay taken.
     */
    public function nameTaken(string $name): bool
    {
        return BloodComponent::withTrashed()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
    }

    public function codeTaken(string $code): bool
    {
        return BloodComponent::withTrashed()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->exists();
    }

    /**
     * One facility's settings, keyed by component id.
     *
     * Returned as a map rather than a list so callers can resolve a component
     * in one lookup instead of scanning, and so a component with no row reads
     * as "not configured here" without a second query.
     *
     * @return SupportCollection<int, FacilityBloodComponent>
     */
    public function settingsFor(int $facilityId): SupportCollection
    {
        return FacilityBloodComponent::query()
            ->where('facility_id', $facilityId)
            ->get()
            ->keyBy('component_id');
    }

    /**
     * This facility's settings, locked for a save that writes several of them.
     *
     * @return SupportCollection<int, FacilityBloodComponent>
     */
    public function lockSettingsFor(int $facilityId): SupportCollection
    {
        return FacilityBloodComponent::query()
            ->where('facility_id', $facilityId)
            ->lockForUpdate()
            ->get()
            ->keyBy('component_id');
    }

    public function setting(int $facilityId, int $componentId): ?FacilityBloodComponent
    {
        return FacilityBloodComponent::query()
            ->where('facility_id', $facilityId)
            ->where('component_id', $componentId)
            ->first();
    }

    /**
     * Create or correct one facility's settings for a component.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertSetting(int $facilityId, int $componentId, array $attributes): FacilityBloodComponent
    {
        $setting = FacilityBloodComponent::query()->updateOrCreate(
            ['facility_id' => $facilityId, 'component_id' => $componentId],
            $attributes
        );

        return $setting->refresh();
    }
}
