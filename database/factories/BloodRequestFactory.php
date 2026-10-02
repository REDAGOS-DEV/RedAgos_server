<?php

namespace Database\Factories;

use App\Enums\BloodRequestStatus;
use App\Enums\IndicationCode;
use App\Enums\RequestPurpose;
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
     * What is being asked for is not here — it lives on the request's lines,
     * which configure() writes after the row exists.
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
            'request_purpose' => RequestPurpose::PatientTransfusion,
            'patient_surname' => fake()->lastName(),
            'patient_first_name' => fake()->firstName(),
            'patient_middle_name' => null,
            'patient_age' => fake()->numberBetween(18, 85),
            'patient_sex' => fake()->randomElement(['male', 'female']),
            // Reference data is reused rather than minted. BloodTypeFactory
            // draws from a unique pool of eight codes, so a nested factory call
            // collides with a blood type the test already seeded — and a test
            // that does not care which type a request is for should not have to
            // pass one in to avoid that.
            'blood_type_id' => fn (): int => BloodType::query()->value('id')
                ?? BloodType::factory()->create()->id,
            'urgency_level' => UrgencyLevel::Routine,
            'status' => BloodRequestStatus::Pending,
            'request_date' => now(),
        ];
    }

    /**
     * Give every request one line, so a bare factory call is still a valid request.
     *
     * A request with no components asks for nothing, and most tests neither
     * know nor care which component they are exercising. States that do care
     * run after this one and amend the line it wrote rather than adding to it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (BloodRequest $request): void {
            $component = BloodComponent::query()->first() ?? BloodComponent::factory()->create();

            $request->items()->create([
                'component_id' => $component->id,
                'quantity' => 2,
                'indication_code' => IndicationCode::forComponentName($component->name)[0] ?? null,
            ]);

            $request->unsetRelation('items');
        });
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
     *
     * Amends the line configure() already wrote rather than adding a second:
     * this state names what a single-component request is for, and appending
     * here would quietly double what the request asks for.
     */
    public function forStock(BloodType $bloodType, BloodComponent $component, int $quantity = 2): static
    {
        return $this
            ->state(fn (array $attributes): array => [
                'blood_type_id' => $bloodType->id,
            ])
            ->afterCreating(function (BloodRequest $request) use ($component, $quantity): void {
                $request->items()->update([
                    'component_id' => $component->id,
                    'quantity' => $quantity,
                    'indication_code' => (IndicationCode::forComponentName($component->name)[0] ?? null)?->value,
                ]);

                $request->unsetRelation('items');
            });
    }

    /**
     * Indicate that the request asks for several components at once.
     *
     * @param  array<int, array{0: BloodComponent, 1: int}>  $lines
     */
    public function withComponents(array $lines): static
    {
        return $this->afterCreating(function (BloodRequest $request) use ($lines): void {
            // Replaces the default line rather than joining it, so the caller
            // gets exactly the components they listed.
            $request->items()->delete();

            foreach ($lines as [$component, $quantity]) {
                $request->items()->create([
                    'component_id' => $component->id,
                    'quantity' => $quantity,
                    'indication_code' => IndicationCode::forComponentName($component->name)[0] ?? null,
                ]);
            }

            $request->unsetRelation('items');
        });
    }

    /**
     * Indicate that the request restocks the requester's own shelves.
     */
    public function replenishment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_purpose' => RequestPurpose::Replenishment,
            'patient_surname' => null,
            'patient_first_name' => null,
            'patient_middle_name' => null,
            'patient_age' => null,
            'patient_sex' => null,
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
