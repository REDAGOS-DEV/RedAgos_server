<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
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
     * Set the role to a canonical application role.
     */
    public function named(RoleName $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $role->value,
        ]);
    }
}
