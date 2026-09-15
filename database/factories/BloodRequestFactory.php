<?php

namespace Database\Factories;

use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BloodRequest>
 */
class BloodRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The two facilities are independent factories on purpose: a default row
     * must never have the same facility on both sides, because a request to
     * oneself is the case the service is required to refuse.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_number' => 'RQ-'.Str::upper(Str::random(10)),
            'facility_id' => Facility::factory(),
            'target_facility_id' => Facility::factory(),
            'requested_by' => User::factory(),
            // Reference data is reused rather than minted. BloodTypeFactory
            // draws from a unique pool of eight codes, so a nested factory call
            // collides with a blood type the test already seeded — and a test
            // that does not care which type a request is for should not have to
            // pass one in to avoid that.
            'blood_type_id' => fn (): int => BloodType::query()->value('id')
                ?? BloodType::factory()->create()->id,
            'component_id' => fn (): int => BloodComponent::query()->value('id')
                ?? BloodComponent::factory()->create()->id,
            'quantity' => 2,
            'urgency_level' => UrgencyLevel::Routine,
            'status' => BloodRequestStatus::Pending,
            'request_date' => now(),
        ];
    }

    /**
     * Indicate which facility raised the request, and who raised it.
     */
    public function raisedBy(Facility $facility, ?User $requester = null): static
    {
        return $this->state(fn (array $attributes): array => array_filter([
            'facility_id' => $facility->id,
            'requested_by' => $requester?->id,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Indicate which facility the request was addressed to.
     */
    public function addressedTo(Facility $facility): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_facility_id' => $facility->id,
        ]);
    }

    /**
     * Indicate the blood type and component being asked for.
     */
    public function forStock(BloodType $bloodType, BloodComponent $component, int $quantity = 2): static
    {
        return $this->state(fn (array $attributes): array => [
            'blood_type_id' => $bloodType->id,
            'component_id' => $component->id,
            'quantity' => $quantity,
        ]);
    }

    public function emergency(): static
    {
        return $this->state(fn (array $attributes): array => [
            'urgency_level' => UrgencyLevel::Emergency,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodRequestStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    /**
     * Indicate that the request has been approved and has stock held for it.
     */
    public function processing(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodRequestStatus::Processing,
            'reviewed_by' => $reviewer?->id ?? User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'No matching stock available.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodRequestStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BloodRequestStatus::Fulfilled,
            'reviewed_at' => now(),
            'fulfilled_at' => now(),
        ]);
    }
}
