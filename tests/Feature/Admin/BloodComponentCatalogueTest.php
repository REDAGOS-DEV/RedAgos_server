<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\BloodComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Component shelf life is what every blood unit's expiry date is derived from.
 *
 * Two things matter here. It is a platform-admin decision, not a facility one,
 * because blood_components is shared by the whole network; and an unset shelf
 * life stays unset rather than acquiring a default, because the value has to
 * come from a clinical owner and inventory intake refuses a component without
 * one instead of guessing.
 */
class BloodComponentCatalogueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeComponent(string $name = 'Packed RBC'): BloodComponent
    {
        return BloodComponent::create(['name' => $name]);
    }

    public function test_the_catalogue_reports_which_components_are_unconfigured(): void
    {
        $this->makeComponent('Whole Blood');
        $this->makeComponent('Platelets');

        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->getJson('/api/admin/blood-components')
            ->assertOk()
            ->assertJsonPath('meta.unconfigured', 2)
            ->assertJsonPath('data.0.shelf_life_configured', false);
    }

    public function test_an_admin_with_the_privilege_can_set_a_shelf_life(): void
    {
        $component = $this->makeComponent();
        $admin = User::factory()->scopedAdmin(['admin.components.manage'])->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/blood-components/{$component->id}", [
                'shelf_life_days' => 42,
                'storage_temperature' => '2-6 °C',
            ])
            ->assertOk()
            ->assertJsonPath('data.shelf_life_days', 42)
            ->assertJsonPath('data.shelf_life_configured', true);

        $this->assertSame(42, $component->fresh()->shelf_life_days);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'admin.component_updated',
        ]);
    }

    /**
     * Null is a real answer, not a missing one: it puts the component back to
     * "no clinically approved value", which intake refuses rather than defaults.
     */
    public function test_a_shelf_life_can_be_cleared_back_to_unconfigured(): void
    {
        $component = BloodComponent::create(['name' => 'Cryoprecipitate', 'shelf_life_days' => 365]);
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/blood-components/{$component->id}", ['shelf_life_days' => null])
            ->assertOk()
            ->assertJsonPath('data.shelf_life_configured', false);

        $this->assertNull($component->fresh()->shelf_life_days);
    }

    public function test_an_admin_without_the_privilege_is_refused(): void
    {
        $component = $this->makeComponent();
        $admin = User::factory()->scopedAdmin(['admin.donor_identity.verify'])->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/blood-components/{$component->id}", ['shelf_life_days' => 42])
            ->assertForbidden();

        $this->assertNull($component->fresh()->shelf_life_days);
    }

    public function test_blood_centre_staff_cannot_reach_the_catalogue(): void
    {
        $component = $this->makeComponent();
        $staff = User::factory()->withRole(RoleName::BloodCenter)->create();

        $this->actingAs($staff)
            ->patchJson("/api/admin/blood-components/{$component->id}", ['shelf_life_days' => 42])
            ->assertForbidden();
    }

    public function test_an_impossible_shelf_life_is_refused(): void
    {
        $component = $this->makeComponent();
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/blood-components/{$component->id}", ['shelf_life_days' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shelf_life_days');
    }
}
