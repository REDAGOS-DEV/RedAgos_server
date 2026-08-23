<?php

namespace Database\Factories;

use App\Models\BloodType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodType>
 */
class BloodTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']);

        return [
            'code' => $code,
            'label' => $code,
        ];
    }

    /**
     * Set an explicit blood type code.
     */
    public function code(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => $code,
            'label' => $code,
        ]);
    }
}
