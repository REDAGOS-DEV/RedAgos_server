<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_id' => Billing::factory(),
            'amount_paid' => 1500.00,
            'payment_method' => PaymentMethod::Cash,
            'reference_number' => null,
            'status' => PaymentStatus::Completed,
            'payment_date' => now(),
        ];
    }

    /**
     * Indicate an electronic settlement, which carries a provider reference.
     */
    public function gcash(?string $reference = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_method' => PaymentMethod::Gcash,
            'reference_number' => $reference ?? 'GC-'.Str::upper(Str::random(12)),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Failed,
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Refunded,
        ]);
    }
}
