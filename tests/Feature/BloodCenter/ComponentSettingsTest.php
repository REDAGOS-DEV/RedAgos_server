<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Models\BloodComponent;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * A blood centre's own shelf life and price for each component.
 *
 * These are held per facility rather than on the shared blood_components row,
 * and that is the whole point of this table: four facilities share that row, so
 * a value written there would decide another centre's expiry dates and — since
 * a non-zero price switches on the payment-before-release gate — could block
 * releases at a facility that never set a price at all.
 */
class ComponentSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $supervisor;

    private BloodComponent $component;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = User::factory()->bloodCenterStaff()->create(['is_supervisor' => true]);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
    }

    public function test_a_supervisor_sees_which_components_are_unconfigured(): void
    {
        BloodComponent::factory()->create(['name' => 'Platelets']);

        $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/blood-components')
            ->assertOk()
            ->assertJsonPath('meta.unconfigured', 2)
            ->assertJsonPath('meta.facility.id', $this->supervisor->facility_id)
            ->assertJsonPath('data.0.shelf_life_configured', false);
    }

    public function test_a_supervisor_can_set_shelf_life_and_price(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", [
                'shelf_life_days' => 42,
                'price' => 1500,
            ])
            ->assertOk()
            ->assertJsonPath('data.shelf_life_days', 42)
            ->assertJsonPath('data.price', 1500)
            ->assertJsonPath('data.shelf_life_configured', true);

        $this->assertDatabaseHas('facility_blood_components', [
            'facility_id' => $this->supervisor->facility_id,
            'component_id' => $this->component->id,
            'shelf_life_days' => 42,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->supervisor->id,
            'action' => 'center.component_configured',
        ]);
    }

    /**
     * The defect this table exists to prevent.
     */
    public function test_one_centres_settings_do_not_reach_another(): void
    {
        $elsewhere = User::factory()->bloodCenterStaff()->create(['is_supervisor' => true]);

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", [
                'shelf_life_days' => 42,
                'price' => 1500,
            ])
            ->assertOk();

        $response = $this->actingAs($elsewhere)
            ->getJson('/api/blood-center/blood-components')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $this->component->id);

        $this->assertNull($row['shelf_life_days']);
        $this->assertNull($row['price']);
        $this->assertFalse($row['shelf_life_configured']);
    }

    public function test_a_second_save_corrects_the_first_rather_than_adding_a_rival_row(): void
    {
        foreach ([30, 35] as $days) {
            $this->actingAs($this->supervisor)
                ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => $days])
                ->assertOk();
        }

        $this->assertSame(1, FacilityBloodComponent::query()
            ->where('facility_id', $this->supervisor->facility_id)
            ->where('component_id', $this->component->id)
            ->count());

        $this->assertSame(35, FacilityBloodComponent::query()
            ->where('facility_id', $this->supervisor->facility_id)
            ->value('shelf_life_days'));
    }

    /**
     * Null is a real answer, not a missing one: it puts the component back to
     * "no approved value", which stock intake refuses rather than defaults.
     */
    public function test_a_shelf_life_can_be_cleared_back_to_unconfigured(): void
    {
        FacilityBloodComponent::create([
            'facility_id' => $this->supervisor->facility_id,
            'component_id' => $this->component->id,
            'shelf_life_days' => 365,
        ]);

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => null])
            ->assertOk()
            ->assertJsonPath('data.shelf_life_configured', false);
    }

    /**
     * center.configure is a MANAGEMENT ability, so no department grants it.
     */
    public function test_a_department_staff_member_cannot_configure_components(): void
    {
        $inventory = User::factory()->bloodCenterStaff()->create([
            'department' => Department::Inventory,
            'is_supervisor' => false,
        ]);

        $this->actingAs($inventory)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => 42])
            ->assertForbidden();

        $this->assertDatabaseCount('facility_blood_components', 0);
    }

    public function test_an_impossible_shelf_life_is_refused(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shelf_life_days');
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", [
                'shelf_life_days' => 42,
                'price' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    }
}
