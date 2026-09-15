<?php

namespace Database\Factories;

use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationScreening>
 */
class DonationScreeningFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donation_id' => Donation::factory(),
            'facility_id' => Facility::factory(),
            'recorded_by' => User::factory(),
            'outcome' => ScreeningOutcome::Qualified,
            'deferral_reason' => null,
            'systolic_bp' => 118,
            'diastolic_bp' => 76,
            'pulse_bpm' => 72,
            'temperature_c' => 36.6,
            'weight_kg' => 65,
            'haemoglobin_g_dl' => 14.2,
            'notes' => null,
            'screened_at' => now(),
        ];
    }

    /**
     * Indicate that the donor was turned away at the counter.
     */
    public function deferred(string $reason = 'Haemoglobin below the accepted threshold.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => ScreeningOutcome::Deferred,
            'deferral_reason' => $reason,
        ]);
    }
}
