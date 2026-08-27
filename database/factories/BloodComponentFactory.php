<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodComponent>
 */
class BloodComponentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Deliberately generic. Tests that need the real five component
            // names run BloodComponentSeeder, so this only has to be unique
            // against the unique name index.
            'name' => ucwords(fake()->unique()->words(2, true)),
            'price' => 0,

            // Left null to match the seeder. Shelf life is a clinical value that
            // must be supplied deliberately, never invented by a factory.
            'shelf_life_days' => null,
            'storage_temperature' => null,
        ];
    }

    /**
     * Give the component a shelf life, for tests that need one configured.
     */
    public function withShelfLife(int $days = 42): static
    {
        return $this->state(fn (array $attributes): array => [
            'shelf_life_days' => $days,
            'storage_temperature' => '1-6 C',
        ]);
    }
}
