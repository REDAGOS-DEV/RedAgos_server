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
            'outcome' => ScreeningOutcome::Accepted,
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
     * Indicate that the donor was turned away and may return.
     */
    public function deferred(string $reason = 'Haemoglobin below the accepted threshold.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => ScreeningOutcome::TemporarilyDeferred,
            'deferral_reason' => $reason,
        ]);
    }

    /**
     * Indicate that the donor may never donate again.
     */
    public function permanentlyDeferred(string $reason = 'Permanent deferral recorded by the screening officer.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => ScreeningOutcome::PermanentlyDeferred,
            'deferral_reason' => $reason,
        ]);
    }

    /**
     * Indicate that the donor was deferred with no expected end.
     */
    public function indefinitelyDeferred(string $reason = 'Deferred pending further assessment.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => ScreeningOutcome::IndefiniteDeferral,
            'deferral_reason' => $reason,
        ]);
    }

    /**
     * Fill in Section I-D, for a record that stands in for a completed form.
     */
    public function examined(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sleep' => '7 hours',
            'meal' => 'Breakfast at 7am',
            'meds' => 'None',
            'allergies' => 'None known',
            'general_appearance' => 'Well, ambulatory',
            'skin' => 'No lesions or puncture marks',
            'heent' => 'Unremarkable',
            'heart_and_lungs' => 'Clear, regular rhythm',
        ]);
    }
}
