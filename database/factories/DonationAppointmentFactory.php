<?php

namespace Database\Factories;

use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationAppointment>
 */
class DonationAppointmentFactory extends Factory
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
            'event_id' => null,
            'appointment_datetime' => now()->addWeek()->setTime(9, 0),
            'status' => 'scheduled',
        ];
    }

    /**
     * Indicate that the appointment has been cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'cancelled',
        ]);
    }
}
