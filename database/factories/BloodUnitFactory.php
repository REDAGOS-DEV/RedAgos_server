<?php

namespace Database\Factories;

use App\Enums\BloodUnitStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Facility;
use App\Support\OperationalDay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BloodUnit>
 */
class BloodUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'RA-'.Str::upper(Str::random(10)),
            'facility_id' => Facility::factory(),
            'component_id' => BloodComponent::factory(),
            'blood_type_id' => BloodType::factory(),
            'donation_id' => Donation::factory(),
            'storage_location' => 'Cold Storage A-1',
            'expiry_date' => OperationalDay::today()->addDays(30)->toDateString(),
            'status' => BloodUnitStatus::Available,
        ];
    }

    /**
     * Indicate that the unit is on the shelf and issuable.
     */
    public function available(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodUnitStatus::Available,
            'expired_at' => null,
            'discarded_at' => null,
            'discard_reason' => null,
        ]);
    }

    /**
     * Indicate that the unit expires a given number of days from today.
     *
     * Accepts a negative value for a unit already past its date but not yet
     * swept, which is what the sweep's own tests need to seed.
     */
    public function expiringIn(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'expiry_date' => OperationalDay::today()->addDays($days)->toDateString(),
        ]);
    }

    /**
     * Indicate that the unit has been swept.
     *
     * Sets expired_at as well, so a factory-made row looks like one the sweep
     * produced rather than a status with no event behind it.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodUnitStatus::Expired,
            'expiry_date' => OperationalDay::today()->subDays(3)->toDateString(),
            'expired_at' => OperationalDay::today()->subDays(2),
        ]);
    }

    /**
     * Indicate that the unit has physically left the building.
     */
    public function discarded(string $reason = 'Seal broken during handling'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodUnitStatus::Discarded,
            'discard_reason' => $reason,
            'discarded_at' => OperationalDay::today(),
        ]);
    }

    /**
     * Indicate that the unit is held for a blood request.
     *
     * Nothing writes this status yet — allocation is module 7 — but the
     * lifecycle guards refuse edits to it, and those refusals need seeding.
     */
    public function reserved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodUnitStatus::Reserved,
        ]);
    }

    /**
     * Indicate that the unit has been issued to a hospital.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodUnitStatus::Issued,
        ]);
    }
}
