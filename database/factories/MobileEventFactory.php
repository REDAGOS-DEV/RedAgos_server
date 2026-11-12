<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\MobileEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileEvent>
 */
class MobileEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Published, as every drive was before drafts existed, so a test that
     * books a factory drive does not first have to publish it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'created_by' => null,
            'name' => fake()->company().' Blood Drive',
            'location' => fake()->streetAddress(),
            'event_date' => now()->addWeeks(2)->toDateString(),
            'published_at' => now(),
        ];
    }

    /**
     * Indicate that the drive has already taken place.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_date' => now()->subWeek()->toDateString(),
        ]);
    }

    /**
     * Indicate that the drive is a draft donors cannot see.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that staff closed the drive to new bookings.
     */
    public function registrationClosed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_closed_at' => now(),
        ]);
    }

    /**
     * Indicate that the drive was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cancelled_at' => now(),
            'cancellation_reason' => 'Venue unavailable.',
        ]);
    }
}
