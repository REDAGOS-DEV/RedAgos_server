<?php

namespace Database\Factories;

use App\Enums\IndicationCode;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BloodRequestItem>
 */
class BloodRequestItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The indication is drawn from the codes the chosen component actually
     * allows, so a factory-built line cannot carry the contradiction the form
     * request exists to refuse.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $component = BloodComponent::query()->first() ?? BloodComponent::factory()->create();

        return [
            'request_id' => BloodRequest::factory(),
            'component_id' => $component->id,
            'quantity' => 2,
            'indication_code' => IndicationCode::forComponentName($component->name)[0] ?? null,
            'indication_other' => null,
        ];
    }

    /**
     * Indicate the component and unit count this line asks for.
     */
    public function forComponent(BloodComponent $component, int $quantity = 2): static
    {
        return $this->state(fn (array $attributes): array => [
            'component_id' => $component->id,
            'quantity' => $quantity,
            'indication_code' => IndicationCode::forComponentName($component->name)[0] ?? null,
        ]);
    }

    /**
     * Indicate an "Others" indication, which the form requires be written out.
     */
    public function withSpecifiedIndication(IndicationCode $code, string $reason): static
    {
        return $this->state(fn (array $attributes): array => [
            'indication_code' => $code,
            'indication_other' => $reason,
        ]);
    }
}
