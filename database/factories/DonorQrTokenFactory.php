<?php

namespace Database\Factories;

use App\Models\DonorProfile;
use App\Models\DonorQrToken;
use App\Models\EligibilityScreening;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DonorQrToken>
 */
class DonorQrTokenFactory extends Factory
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
            'screening_id' => EligibilityScreening::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'issued_at' => now(),
            'expires_at' => now()->addDays((int) config('donation.qr_validity_days')),
        ];
    }

    /**
     * Indicate that the token has already lapsed.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'issued_at' => now()->subDays(30),
            'expires_at' => now()->subDays(16),
        ]);
    }
}
