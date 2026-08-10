<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\MobileEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileEvent>
 */
class MobileEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'created_by' => null,
            'name' => fake()->company().' Blood Drive',
            'location' => fake()->streetAddress(),
            'event_date' => now()->addWeeks(2)->toDateString(),
            'max_capacity' => 60,
        ];
    }

    /**
     * Indicate that the drive has already taken place.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_date' => now()->subWeek()->toDateString(),
        ]);
    }
}
