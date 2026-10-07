<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\User;
use App\Models\WeeklyRequest;
use App\Support\OperationalDay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WeeklyRequest>
 */
class WeeklyRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A bare header: its blood requests are made separately, with
     * BloodRequestFactory::weekly(), so a test says which blood types it sent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_number' => 'WR-'.Str::upper(Str::random(10)),
            'facility_id' => Facility::factory()->bloodBank(),
            'target_facility_id' => Facility::factory(),
            'request_day' => OperationalDay::todayAsDate(),
            'requested_by' => User::factory(),
        ];
    }

    /**
     * Indicate the hospital that sent it, and the staff member who did.
     */
    public function raisedBy(Facility $hospital, ?User $requester = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'facility_id' => $hospital->id,
            'requested_by' => $requester?->id ?? User::factory()->bloodBankStaff($hospital),
        ]);
    }

    /**
     * Indicate the centre it was sent to.
     */
    public function addressedTo(Facility $centre): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_facility_id' => $centre->id,
        ]);
    }
}
