<?php

namespace App\Repository;

use App\Models\BloodComponent;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads and writes the platform's blood component catalogue.
 *
 * There is no facility scoping here, and that is not an omission: the
 * `blood_components` table carries no `facility_id` and its `name` is globally
 * unique, so a component is one row shared by every centre on the network.
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
     * @param  array<string, mixed>  $attributes
     */
    public function update(BloodComponent $component, array $attributes): BloodComponent
    {
        $component->fill($attributes);
        $component->save();

        return $component->refresh();
    }
}
