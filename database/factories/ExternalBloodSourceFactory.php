<?php

namespace Database\Factories;

use App\Models\ExternalBloodSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalBloodSource>
 */
class ExternalBloodSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Blood Service',
            'code' => null,
        ];
    }
}
