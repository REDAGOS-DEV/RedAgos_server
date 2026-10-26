<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * A blood centre's own shelf life for each component, and the catalogue Processing adds to.
 *
 * Shelf life is held per facility rather than on the shared blood_components
 * row, and that is the whole point of the table: four facilities share that
 * row, so a value written there would decide another centre's expiry dates.
 *
 * Since 2026-10-15 it is Processing's to set (components.configure, the
 * Component Technologist's), and the price on the same row is Billing's
 * (ComponentPricesTest).
 */
class ComponentSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $technologist;

    private BloodComponent $component;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technologist = User::factory()->bloodCenterStaff(post: StaffRole::ComponentTechnologist)->create();
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
    }

    public function test_the_technologist_sees_which_components_are_unconfigured(): void
    {
        BloodComponent::factory()->create(['name' => 'Platelet Concentrate']);

        $this->actingAs($this->technologist)
            ->getJson('/api/blood-center/blood-components')
            ->assertOk()
            ->assertJsonPath('meta.unconfigured', 2)
            ->assertJsonPath('meta.facility.id', $this->technologist->facility_id)
            ->assertJsonPath('data.0.shelf_life_configured', false)
            // The price is Billing's and is not served here.
            ->assertJsonMissingPath('data.0.price');
    }

    public function test_the_technologist_sets_a_shelf_life(): void
    {
        $this->actingAs($this->technologist)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => 42])
            ->assertOk()
            ->assertJsonPath('data.shelf_life_days', 42)
            ->assertJsonPath('data.shelf_life_configured', true);

        $this->assertDatabaseHas('facility_blood_components', [
            'facility_id' => $this->technologist->facility_id,
            'component_id' => $this->component->id,
            'shelf_life_days' => 42,
        ]);

        $audit = AuditLog::query()->where('action', 'center.component_configured')->sole();
        $this->assertSame($this->technologist->id, $audit->actor_id);
        $this->assertNull($audit->context['shelf_life_days_before']);
        $this->assertSame(42, $audit->context['shelf_life_days_after']);
    }

    public function test_a_shelf_life_save_leaves_the_price_alone(): void
    {
        // Billing priced it first; Processing's save must not clear that.
        FacilityBloodComponent::create([
            'facility_id' => $this->technologist->facility_id,
            'component_id' => $this->component->id,
            'price' => 1500,
        ]);

        $this->actingAs($this->technologist)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => 35])
            ->assertOk();

        $setting = FacilityBloodComponent::query()->sole();
        $this->assertSame(35, $setting->shelf_life_days);
        $this->assertSame('1500.00', $setting->price);
    }

    public function test_a_price_sent_here_is_refused_rather_than_ignored(): void
    {
        $this->actingAs($this->technologist)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", [
                'shelf_life_days' => 42,
                'price' => 1500,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $this->assertDatabaseCount('facility_blood_components', 0);
    }

    /**
     * The defect this table exists to prevent.
     */
    public function test_one_centres_settings_do_not_reach_another(): void
    {
        $elsewhere = User::factory()->bloodCenterStaff(post: StaffRole::ComponentTechnologist)->create();

        $this->actingAs($this->technologist)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => 42])
            ->assertOk();

        $response = $this->actingAs($elsewhere)
            ->getJson('/api/blood-center/blood-components')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $this->component->id);

        $this->assertNull($row['shelf_life_days']);
        $this->assertFalse($row['shelf_life_configured']);
    }

    public function test_a_second_save_corrects_the_first_rather_than_adding_a_rival_row(): void
    {
        foreach ([30, 35] as $days) {
            $this->actingAs($this->technologist)
                ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => $days])
                ->assertOk();
        }

        $this->assertSame(1, FacilityBloodComponent::query()
            ->where('facility_id', $this->technologist->facility_id)
            ->where('component_id', $this->component->id)
            ->count());

        $this->assertSame(35, FacilityBloodComponent::query()
            ->where('facility_id', $this->technologist->facility_id)
            ->value('shelf_life_days'));
    }

    /**
     * Null is a real answer, not a missing one: it puts the component back to
     * "no approved value", which stock intake refuses rather than defaults.
     */
    public function test_a_shelf_life_can_be_cleared_back_to_unconfigured(): void
    {
        FacilityBloodComponent::create([
            'facility_id' => $this->technologist->facility_id,
            'component_id' => $this->component->id,
            'shelf_life_days' => 365,
        ]);

        $this->actingAs($this->technologist)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => null])
            ->assertOk()
            ->assertJsonPath('data.shelf_life_configured', false);
    }

    /**
     * Processing's head alone: not the assistant, not another department, not
     * a custom Processing role, and neither management level.
     */
    public function test_only_the_component_technologist_configures_components(): void
    {
        $facility = $this->technologist->facility;

        $refused = [
            User::factory()->bloodCenterStaff($facility, StaffRole::ProcessingAssistant)->create(),
            User::factory()->bloodCenterStaff($facility, StaffRole::InventoryControlOfficer)->create(),
            User::factory()->bloodCenterStaff($facility, StaffRole::BillingSupervisor)->create(),
            User::factory()->bloodCenterCustomStaff($facility, Department::Processing)->create(),
            User::factory()->bloodCenterAdmin($facility)->create(),
            User::factory()->bloodCenterSupervisor($facility)->create(),
        ];

        foreach ($refused as $user) {
            $this->actingAs($user)->getJson('/api/blood-center/blood-components')->assertForbidden();

            $this->actingAs($user)
                ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => 42])
                ->assertForbidden();

            $this->actingAs($user)
                ->postJson('/api/blood-center/blood-components', ['name' => 'Washed Platelets', 'code' => 'WPC'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('facility_blood_components', 0);
        $this->assertDatabaseMissing('blood_components', ['name' => 'Washed Platelets']);
    }

    public function test_an_impossible_shelf_life_is_refused(): void
    {
        $this->actingAs($this->technologist)
            ->patchJson("/api/blood-center/blood-components/{$this->component->id}", ['shelf_life_days' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shelf_life_days');
    }

    public function test_the_technologist_adds_a_component_with_its_shelf_life(): void
    {
        $response = $this->actingAs($this->technologist)
            ->postJson('/api/blood-center/blood-components', [
                'name' => '  Leukoreduced   Packed RBC ',
                'code' => 'lprbc',
                'storage_temperature' => '1-6 °C',
                'shelf_life_days' => 35,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Leukoreduced Packed RBC')
            ->assertJsonPath('data.code', 'LPRBC')
            ->assertJsonPath('data.shelf_life_days', 35)
            ->assertJsonPath('data.shelf_life_configured', true);

        $component = BloodComponent::query()->findOrFail($response->json('data.id'));

        $this->assertSame('LPRBC', $component->code);
        $this->assertSame('1-6 °C', $component->storage_temperature);

        $this->assertDatabaseHas('facility_blood_components', [
            'facility_id' => $this->technologist->facility_id,
            'component_id' => $component->id,
            'shelf_life_days' => 35,
            'price' => null,
        ]);

        $added = AuditLog::query()->where('action', 'center.component_added')->sole();
        $this->assertSame($this->technologist->id, $added->actor_id);
        $this->assertSame('LPRBC', $added->context['code']);
        $this->assertSame(1, AuditLog::query()->where('action', 'center.component_configured')->count());
    }

    public function test_a_component_added_without_a_shelf_life_stays_unconfigured(): void
    {
        $this->actingAs($this->technologist)
            ->postJson('/api/blood-center/blood-components', ['name' => 'Washed Platelets', 'code' => 'WPC'])
            ->assertCreated()
            ->assertJsonPath('data.shelf_life_days', null)
            ->assertJsonPath('data.shelf_life_configured', false);

        $this->assertDatabaseCount('facility_blood_components', 0);
    }

    /**
     * The catalogue is shared: a component one centre adds, every centre sees,
     * unconfigured until it sets its own shelf life.
     */
    public function test_another_centre_sees_the_new_component_unconfigured(): void
    {
        $elsewhere = User::factory()->bloodCenterStaff(post: StaffRole::ComponentTechnologist)->create();

        $id = $this->actingAs($this->technologist)
            ->postJson('/api/blood-center/blood-components', ['name' => 'Washed Platelets', 'code' => 'WPC', 'shelf_life_days' => 5])
            ->assertCreated()
            ->json('data.id');

        $row = collect($this->actingAs($elsewhere)->getJson('/api/blood-center/blood-components')->json('data'))
            ->firstWhere('id', $id);

        $this->assertNotNull($row);
        $this->assertNull($row['shelf_life_days']);
        $this->assertFalse($row['shelf_life_configured']);
    }

    public function test_a_name_or_code_already_taken_is_refused_whatever_its_case(): void
    {
        BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma', 'code' => 'FFP']);

        $this->actingAs($this->technologist)
            ->postJson('/api/blood-center/blood-components', ['name' => 'packed rbc', 'code' => 'NEW'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($this->technologist)
            ->postJson('/api/blood-center/blood-components', ['name' => 'Something New', 'code' => 'ffp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_a_retired_components_name_and_code_stay_taken(): void
    {
        BloodComponent::factory()->create(['name' => 'Whole Blood', 'code' => 'WB'])->delete();

        $this->actingAs($this->technologist)
            ->postJson('/api/blood-center/blood-components', ['name' => 'Whole Blood', 'code' => 'WB'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code']);
    }

    public function test_a_code_must_be_a_bag_number_suffix(): void
    {
        foreach (['', '2', 'P-RBC', 'TOOLONGCODE1'] as $code) {
            $this->actingAs($this->technologist)
                ->postJson('/api/blood-center/blood-components', ['name' => 'Washed Platelets', 'code' => $code])
                ->assertStatus(422)
                ->assertJsonValidationErrors('code');
        }

        $this->assertDatabaseMissing('blood_components', ['name' => 'Washed Platelets']);
    }
}
