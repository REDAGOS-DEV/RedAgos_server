<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donor_id' => DonorProfile::factory(),
            'facility_id' => Facility::factory(),
            'appointment_id' => null,
            'donation_date' => now()->subMonths(4),
            'status' => 'completed',
            'volume_ml' => 450,
        ];
    }

    /**
     * Indicate that the donation was completed on a given date.
     */
    public function completedAt(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'completed',
            'donation_date' => $date,
        ]);
    }

    /**
     * Indicate that the donor was turned away at the centre.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'rejected',
            'volume_ml' => null,
        ]);
    }
}
