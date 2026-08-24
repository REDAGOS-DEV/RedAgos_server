<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BloodUnitStatusEnumTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_values_matches_the_cases(): void
    {
        $this->assertSame(
            ['available', 'reserved', 'issued', 'expired', 'discarded'],
            BloodUnitStatus::values()
        );
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (BloodUnitStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }

    public function test_reference_data_projects_exactly_the_enum_cases(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $response = $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk();

        // Not a comparison against migration text — the migration builds its
        // column from this enum, so drift is structurally impossible. This
        // asserts the API projection instead.
        $this->assertSame(
            array_map(
                fn (BloodUnitStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                BloodUnitStatus::cases()
            ),
            $response->json('statuses')
        );
    }
}
