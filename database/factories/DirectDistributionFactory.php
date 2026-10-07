<?php

namespace Database\Factories;

use App\Models\DirectDistribution;
use App\Models\ExternalBloodSource;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectDistribution>
 */
class DirectDistributionFactory extends Factory
{
    /**
     * Define the model's default state: a Red Cross bag received just now.
     *
     * transfusion_request_id has no default — a receipt is always for a
     * requirement, and tests record one through the portal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory()->bloodBank(),
            'external_blood_source_id' => fn (): int => ExternalBloodSource::query()->where('code', 'PRC')->value('id')
                ?? ExternalBloodSource::factory()->create()->id,
            'external_unit_number' => 'PRC-'.fake()->unique()->numerify('#########'),
            'collection_date' => null,
            'received_at' => now(),
            'received_by' => User::factory(),
        ];
    }

    /**
     * Indicate the hospital that received the bag, and the staff member who did.
     */
    public function receivedBy(Facility $hospital, ?User $staff = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'facility_id' => $hospital->id,
            'received_by' => $staff?->id ?? User::factory()->bloodBankStaff($hospital),
        ]);
    }
}
