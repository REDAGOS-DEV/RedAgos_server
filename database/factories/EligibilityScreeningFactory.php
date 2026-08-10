<?php

namespace Database\Factories;

use App\Enums\EligibilityStatus;
use App\Models\DonorProfile;
use App\Models\EligibilityScreening;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EligibilityScreening>
 */
class EligibilityScreeningFactory extends Factory
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
            'question_version' => 1,
            'screened_at' => now(),
            'valid_until' => now()->addDays((int) config('donation.screening_validity_days')),
            'result' => EligibilityStatus::Eligible,
            'computed_result' => 'eligible',
            'submitted_result' => 'eligible',
            'age_at_screening' => 30,
            'weight_kg' => 65,
            'deferral_reasons' => [],
        ];
    }

    /**
     * Indicate that the screening deferred the donor.
     */
    public function deferred(): static
    {
        return $this->state(fn (array $attributes): array => [
            'result' => EligibilityStatus::Deferred,
            'computed_result' => 'deferred',
            'deferral_reasons' => [
                ['code' => 'questionnaire_response', 'message' => 'Flagged for review.'],
            ],
        ]);
    }

    /**
     * Indicate that the screening has aged past its validity window.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'screened_at' => now()->subDays(120),
            'valid_until' => now()->subDays(30),
        ]);
    }
}
