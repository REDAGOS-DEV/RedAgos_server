<?php

namespace Database\Factories;

use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\HospitalUnit;
use App\Models\UnitTag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitTag>
 */
class UnitTagFactory extends Factory
{
    /**
     * Define the model's default state: a fresh Tag Assigned with its full 24 hours.
     *
     * Writes the tag only. The bag's own status is the caller's to set — use
     * the matching HospitalUnitFactory state — so a test cannot assume a
     * factory moved two rows when it moved one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $taggedAt = now()->startOfSecond();

        return [
            'hospital_unit_id' => HospitalUnit::factory()->tagAssigned(),
            'facility_id' => fn (array $attributes): int => HospitalUnit::query()
                ->findOrFail($attributes['hospital_unit_id'])->facility_id,
            'transfusion_request_id' => null,
            'patient_surname' => fake()->lastName(),
            'patient_first_name' => fake()->firstName(),
            'patient_middle_name' => null,
            'patient_age' => fake()->numberBetween(18, 85),
            'patient_sex' => fake()->randomElement(['male', 'female']),
            'patient_record_number' => 'HRN-'.fake()->numerify('######'),
            'patient_ward' => 'Medical Ward 2',
            'status' => UnitTagStatus::TagAssigned,
            'tagged_at' => $taggedAt,
            'tagged_by' => User::factory(),
            'crossmatch_deadline_at' => $taggedAt->addHours(24),
        ];
    }

    /**
     * Place the tag on a specific bag.
     */
    public function forUnit(HospitalUnit $unit): static
    {
        return $this->state(fn (array $attributes): array => [
            'hospital_unit_id' => $unit->id,
            'facility_id' => $unit->facility_id,
        ]);
    }

    /**
     * Indicate the tag was crossmatched and the bag awaits transfusion.
     */
    public function crossmatched(): static
    {
        return $this->state(function (array $attributes): array {
            $crossmatchedAt = now()->startOfSecond();

            return [
                'status' => UnitTagStatus::TagCrossmatched,
                'crossmatched_at' => $crossmatchedAt,
                'transfusion_deadline_at' => $crossmatchedAt->addHours(24),
            ];
        });
    }

    /**
     * Indicate the tag's crossmatch period ran out.
     */
    public function untaggedAssigned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UnitTagStatus::UntaggedAssigned,
            'untagged_at' => now()->startOfSecond(),
            'untag_reason' => UntagReason::CrossmatchDeadlineExpired,
        ]);
    }
}
