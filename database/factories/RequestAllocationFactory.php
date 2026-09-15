<?php

namespace Database\Factories;

use App\Enums\AllocationStatus;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\RequestAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestAllocation>
 */
class RequestAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => BloodRequest::factory(),
            'unit_id' => BloodUnit::factory(),
            'allocated_at' => now(),
            'allocated_by' => User::factory(),
            'status' => AllocationStatus::Allocated,
        ];
    }

    /**
     * Attach the allocation to a specific request and unit.
     */
    public function holding(BloodRequest $request, BloodUnit $unit): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_id' => $request->id,
            'unit_id' => $unit->id,
        ]);
    }

    /**
     * Indicate that the unit has been dispatched but not yet confirmed.
     */
    public function released(?User $releasedBy = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AllocationStatus::Released,
            'released_at' => now(),
            'released_by' => $releasedBy?->id ?? User::factory(),
        ]);
    }

    /**
     * Indicate that the requesting facility has confirmed the unit arrived.
     *
     * Sets the release fields too: a unit cannot be received without having
     * been dispatched, and a factory row that claims otherwise would let a
     * test pass against a state the workflow cannot produce.
     */
    public function received(?User $receivedBy = null): static
    {
        return $this->released()->state(fn (array $attributes): array => [
            'received_at' => now(),
            'received_by' => $receivedBy?->id ?? User::factory(),
        ]);
    }

    /**
     * Indicate that the hold was given up and the unit returned to stock.
     */
    public function cancelled(string $reason = 'Hold released at requester request.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AllocationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }
}
