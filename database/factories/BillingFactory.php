<?php

namespace Database\Factories;

use App\Enums\BillingStatus;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Billing>
 */
class BillingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults to the subsidised case, because that is what the system
     * currently produces: a zero statement, settled on creation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => BloodRequest::factory(),
            'billed_by' => User::factory(),
            'billing_date' => now(),
            'total_amount' => 0,
            'status' => BillingStatus::Paid,
        ];
    }

    /**
     * Indicate a statement that asks for money and has not been settled.
     */
    public function chargeable(float $amount = 1500.00): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount' => $amount,
            'status' => BillingStatus::Unpaid,
        ]);
    }

    public function partiallyPaid(float $amount = 1500.00): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount' => $amount,
            'status' => BillingStatus::Partial,
        ]);
    }

    public function paid(float $amount = 1500.00): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount' => $amount,
            'status' => BillingStatus::Paid,
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BillingStatus::Void,
        ]);
    }
}
