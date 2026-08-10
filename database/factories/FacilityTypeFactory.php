<?php

namespace Database\Factories;

use App\Models\FacilityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacilityType>
 */
class FacilityTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
        ];
    }

    /**
     * Use the blood centre facility type donors can book against.
     */
    public function bloodCenter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'blood_center',
        ]);
    }
}
