<?php

namespace Database\Factories;

use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\StockThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockThreshold>
 */
class StockThresholdFactory extends Factory
{
    /**
     * Define the model's default state: a monitored cell that has not alerted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'blood_type_id' => BloodType::factory(),
            'component_id' => BloodComponent::factory(),
            'minimum_units' => 10,
            'alerts_enabled' => true,
            'alerted_at' => null,
        ];
    }

    /**
     * Indicate the facility, blood type and component the minimum is for.
     */
    public function forCell(Facility $facility, BloodType $bloodType, BloodComponent $component): static
    {
        return $this->state(fn (array $attributes): array => [
            'facility_id' => $facility->id,
            'blood_type_id' => $bloodType->id,
            'component_id' => $component->id,
        ]);
    }

    /**
     * Indicate the minimum, in units.
     */
    public function minimum(int $units): static
    {
        return $this->state(fn (array $attributes): array => ['minimum_units' => $units]);
    }

    /**
     * Indicate notifications are switched off for this cell.
     */
    public function alertsOff(): static
    {
        return $this->state(fn (array $attributes): array => ['alerts_enabled' => false]);
    }

    /**
     * Indicate a low episode already announced.
     */
    public function alerted(): static
    {
        return $this->state(fn (array $attributes): array => ['alerted_at' => now()->startOfSecond()]);
    }
}
