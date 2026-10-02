<?php

namespace Database\Factories;

use App\Enums\HospitalUnitStatus;
use App\Models\BloodUnit;
use App\Models\HospitalUnit;
use App\Models\RequestAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HospitalUnit>
 */
class HospitalUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A received hold of an issued bag, held by the hospital that raised the
     * request — the only path by which a bag reaches a hospital's custody. The
     * unit and facility are read from that hold rather than generated, so the
     * row cannot describe a bag the hold does not name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_allocation_id' => RequestAllocation::factory()
                ->received()
                ->state(['unit_id' => BloodUnit::factory()->issued()]),
            'unit_id' => fn (array $attributes): string => RequestAllocation::query()
                ->findOrFail($attributes['request_allocation_id'])->unit_id,
            'facility_id' => fn (array $attributes): int => RequestAllocation::query()
                ->findOrFail($attributes['request_allocation_id'])->request->facility_id,
            'status' => HospitalUnitStatus::Available,
        ];
    }

    /**
     * Stock the bag a specific received hold delivered.
     */
    public function forAllocation(RequestAllocation $allocation): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_allocation_id' => $allocation->id,
            'unit_id' => $allocation->unit_id,
            'facility_id' => $allocation->request->facility_id,
        ]);
    }

    /**
     * Indicate the bag is held for a patient awaiting crossmatch.
     *
     * Only the unit's half: pair it with a UnitTagFactory tag on the same unit.
     */
    public function tagAssigned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HospitalUnitStatus::TagAssigned,
        ]);
    }

    /**
     * Indicate the bag has left storage for a crossmatched patient.
     */
    public function tagCrossmatched(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HospitalUnitStatus::TagCrossmatched,
        ]);
    }

    /**
     * Indicate the bag came back out of a crossmatched tag and awaits return to storage.
     */
    public function pendingReturn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HospitalUnitStatus::PendingReturn,
        ]);
    }
}
