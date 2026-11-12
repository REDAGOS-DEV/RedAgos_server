<?php

namespace Database\Factories;

use App\Enums\PreRegistrationSource;
use App\Enums\PreRegistrationStatus;
use App\Models\DrivePartnerSubmission;
use App\Models\DrivePreRegistration;
use App\Models\MobileEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrivePreRegistration>
 */
class DrivePreRegistrationFactory extends Factory
{
    /**
     * Someone who put themselves on the list through the drive's link.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mobile_event_id' => MobileEvent::factory(),
            'source' => PreRegistrationSource::Individual,
            'status' => PreRegistrationStatus::PreRegistered,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+639'.fake()->unique()->numerify('#########'),
            'consent_at' => now(),
        ];
    }

    /**
     * Indicate that a partner organisation sent this person in a batch.
     */
    public function fromPartner(?DrivePartnerSubmission $submission = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => PreRegistrationSource::Partner,
            // Resolved last, so the batch belongs to the drive the entry ends up on.
            'partner_submission_id' => fn (array $resolved): int => $submission?->id
                ?? DrivePartnerSubmission::factory()->create(['mobile_event_id' => $resolved['mobile_event_id']])->id,
            'consent_at' => null,
        ]);
    }

    /**
     * Indicate that the person was taken off the list.
     */
    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PreRegistrationStatus::Withdrawn,
            'withdrawn_at' => now(),
        ]);
    }
}
