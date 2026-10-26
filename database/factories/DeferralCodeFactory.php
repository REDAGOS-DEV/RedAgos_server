<?php

namespace Database\Factories;

use App\Models\DeferralCode;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeferralCode>
 */
class DeferralCodeFactory extends Factory
{
    /**
     * Define the model's default state: an active code.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'code' => 'X'.fake()->unique()->numberBetween(1, 9999),
            'reason' => fake()->sentence(3),
        ];
    }

    /**
     * Indicate a code no longer offered when recording.
     */
    public function retired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'retired_at' => now(),
        ]);
    }
}
