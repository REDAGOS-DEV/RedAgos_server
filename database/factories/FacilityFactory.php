<?php

namespace Database\Factories;

use App\Enums\FacilityStatus;
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
            'doh_license_number' => 'DOH-BC-'.fake()->unique()->numerify('########'),
            'contact_person' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+639'.fake()->numerify('#########'),
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

    /**
     * A facility cleared to act on real data.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FacilityStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    /**
     * A registration still awaiting an administrator's decision.
     */
    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FacilityStatus::PendingApproval,
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    /**
     * A registration an administrator turned down.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FacilityStatus::Rejected,
            'rejection_reason' => 'The DOH licence could not be verified.',
        ]);
    }

    /**
     * A previously approved facility taken out of service.
     *
     * Staff keep the blood_center role here, which is exactly why the
     * operational middleware has to check status as well as role.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FacilityStatus::Suspended,
            'approved_at' => now()->subMonth(),
            'rejection_reason' => 'Under investigation.',
        ]);
    }
}
