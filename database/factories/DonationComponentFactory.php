<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationComponent>
 */
class DonationComponentFactory extends Factory
{
    protected $model = DonationComponent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donation_id' => Donation::factory(),
            'component_id' => BloodComponent::factory(),
            'quantity' => 1,
            'declared_by' => User::factory(),
        ];
    }

    /**
     * Declare a generous yield, for tests that record several units.
     */
    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes): array => ['quantity' => $quantity]);
    }
}
