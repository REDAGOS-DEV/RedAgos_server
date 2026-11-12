<?php

namespace Database\Factories;

use App\Models\DrivePartnerSubmission;
use App\Models\MobileEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrivePartnerSubmission>
 */
class DrivePartnerSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mobile_event_id' => MobileEvent::factory(),
            'organisation_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'contact_phone' => '+639'.fake()->numerify('#########'),
            'contact_email' => fake()->safeEmail(),
            'consent_at' => now(),
        ];
    }
}
