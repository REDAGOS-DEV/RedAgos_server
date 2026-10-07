<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\ReplenishmentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplenishmentSchedule>
 */
class ReplenishmentScheduleFactory extends Factory
{
    /**
     * Define the model's default state: Monday, Wednesday and Friday.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory()->bloodBank(),
            'target_facility_id' => Facility::factory(),
            'days_of_week' => [1, 3, 5],
        ];
    }

    /**
     * Indicate the hospital keeping the schedule and the centre it is for.
     */
    public function between(Facility $hospital, Facility $centre): static
    {
        return $this->state(fn (array $attributes): array => [
            'facility_id' => $hospital->id,
            'target_facility_id' => $centre->id,
        ]);
    }

    /**
     * Indicate the ISO weekdays (1 = Monday … 7 = Sunday) requests may be sent on.
     *
     * @param  array<int, int>  $days
     */
    public function on(array $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'days_of_week' => array_values($days),
        ]);
    }
}
