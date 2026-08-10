<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\FacilityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // facility_types.name is unique, so the blood centre type is shared
            // across facilities rather than recreated per factory call.
            'facility_type_id' => fn (): int => FacilityType::firstOrCreate(['name' => 'blood_center'])->id,
            'name' => fake()->unique()->company().' Blood Center',
            'address' => fake()->city(),
            'operating_hours' => 'Mon - Fri 8 AM - 3 PM',
            'is_accepting_donations' => true,
            'slot_capacity' => 4,
            'slot_interval_minutes' => 30,
            'slots_start_at' => '08:00',
            'slots_end_at' => '15:00',
        ];
    }

    /**
     * Indicate that the facility is closed to donor bookings.
     */
    public function notAcceptingDonations(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_accepting_donations' => false,
        ]);
    }

    /**
     * Restrict the facility to a single bookable slot per interval.
     */
    public function singleSlot(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slot_capacity' => 1,
            'slots_start_at' => '08:00',
            'slots_end_at' => '08:30',
        ]);
    }
}
