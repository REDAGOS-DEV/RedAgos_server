<?php

namespace Database\Factories;

use App\Enums\ScreeningOutcome;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonorDeferral>
 */
class DonorDeferralFactory extends Factory
{
    /**
     * Define the model's default state: a temporary deferral in force for a month.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donor_id' => User::factory()->donor(),
            'facility_id' => Facility::factory(),
            'outcome' => ScreeningOutcome::TemporarilyDeferred,
            'reason' => 'Low haemoglobin',
            'deferred_on' => OperationalDay::todayAsDate(),
            'ends_on' => OperationalDay::today()->addDays(30)->toDateString(),
            'recorded_by' => null,
        ];
    }

    /**
     * Indicate a permanent deferral, which has no end date.
     */
    public function permanent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => ScreeningOutcome::PermanentlyDeferred,
            'ends_on' => null,
        ]);
    }

    /**
     * Indicate a deferral that has been lifted.
     */
    public function lifted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'lifted_at' => now(),
            'lift_reason' => 'Cleared on review',
        ]);
    }
}
