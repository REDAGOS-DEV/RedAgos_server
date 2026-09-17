<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_the_expected_shape(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk()
            ->assertJsonStructure([
                'blood_types',
                'components',
                'statuses' => [['value', 'label']],
                'storage_locations',
                'facility' => ['id', 'facility_name', 'address'],
            ]);
    }

    public function test_it_returns_blood_types_and_components(): void
    {
        // Idempotent: DonorProfileFactory also creates random blood types.
        BloodType::firstOrCreate(['code' => 'O-'], ['label' => 'O-']);
        $this->seed(BloodComponentSeeder::class);

        $staff = User::factory()->bloodCenterStaff()->create();

        $response = $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk();

        $this->assertContains('O-', array_column($response->json('blood_types'), 'code'));
        $this->assertContains('Packed RBC', array_column($response->json('components'), 'name'));
    }

    public function test_components_report_that_shelf_life_is_not_configured(): void
    {
        $this->seed(BloodComponentSeeder::class);

        $staff = User::factory()->bloodCenterStaff()->create();

        $response = $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk();

        // The UI uses this to disable stock entry rather than let someone record
        // a unit with an invented expiry date.
        foreach ($response->json('components') as $component) {
            $this->assertNull($component['shelf_life_days']);
            $this->assertFalse($component['shelf_life_configured']);
        }
    }

    public function test_a_configured_shelf_life_is_reported(): void
    {
        $component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $staff = User::factory()->bloodCenterStaff()->create();

        // Set against this facility, not the shared catalogue row: four
        // facilities share that row, so a value written there would be read by
        // all of them.
        FacilityBloodComponent::create([
            'facility_id' => $staff->facility_id,
            'component_id' => $component->id,
            'shelf_life_days' => 42,
        ]);

        $response = $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk();

        $row = collect($response->json('components'))->firstWhere('name', 'Packed RBC');

        $this->assertSame(42, $row['shelf_life_days']);
        $this->assertTrue($row['shelf_life_configured']);
    }

    /**
     * The whole reason these settings are per facility: one centre's shelf life
     * must not become another centre's, because it decides when their units
     * expire.
     */
    public function test_another_facilitys_shelf_life_is_not_reported_here(): void
    {
        $component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $staff = User::factory()->bloodCenterStaff()->create();
        $elsewhere = User::factory()->bloodCenterStaff()->create();

        FacilityBloodComponent::create([
            'facility_id' => $elsewhere->facility_id,
            'component_id' => $component->id,
            'shelf_life_days' => 42,
        ]);

        $response = $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk();

        $row = collect($response->json('components'))->firstWhere('name', 'Packed RBC');

        $this->assertNull($row['shelf_life_days']);
        $this->assertFalse($row['shelf_life_configured']);
    }

    public function test_the_statuses_are_projected_from_the_enum(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $response = $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk();

        $this->assertSame(
            BloodUnitStatus::values(),
            array_column($response->json('statuses'), 'value')
        );
    }

    public function test_it_returns_the_callers_own_facility(): void
    {
        $facility = Facility::factory()->approved()->create(['name' => 'My Center']);
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        Facility::factory()->approved()->create(['name' => 'Someone Elses Center']);

        $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk()
            ->assertJsonPath('facility.facility_name', 'My Center')
            ->assertJsonPath('facility.id', $facility->id);
    }

    public function test_storage_locations_come_from_config(): void
    {
        config(['blood_center.storage_locations' => ['Cold Storage Z-9']]);

        $staff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/reference-data')
            ->assertOk()
            ->assertJsonPath('storage_locations', ['Cold Storage Z-9']);
    }

    public function test_it_rejects_a_donor(): void
    {
        $this->actingAs(User::factory()->donor()->create())
            ->getJson('/api/blood-center/reference-data')
            ->assertForbidden();
    }
}
