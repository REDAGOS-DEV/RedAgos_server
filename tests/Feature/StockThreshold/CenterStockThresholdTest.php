<?php

namespace Tests\Feature\StockThreshold;

use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\StockThreshold;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Feature\StockThreshold\Concerns\BuildsThresholdStock;
use Tests\TestCase;

/**
 * A blood centre's minimum stock per blood type and component.
 *
 * Counts are derived from the units under the Daily Stock Report's rule, and
 * the minimum is the only thing stored. Reading is everyone with
 * `inventory.view`; setting is the Inventory Control Officer and the Center
 * Admin.
 */
class CenterStockThresholdTest extends TestCase
{
    use BuildsThresholdStock, LazilyRefreshDatabase;

    private Facility $centre;

    private User $supervisor;

    private User $officer;

    private BloodType $oPositive;

    private BloodComponent $prbc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->supervisor = User::factory()->bloodCenterStaff($this->centre)->create(['is_supervisor' => true]);
        $this->officer = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();

        $this->oPositive = $this->bloodType('O+');
        $this->prbc = BloodComponent::factory()->create(['name' => 'Packed RBC']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/blood-center/inventory/thresholds')->assertUnauthorized();
        $this->putJson('/api/blood-center/inventory/thresholds', [])->assertUnauthorized();
    }

    public function test_status_lists_every_blood_type_against_every_component(): void
    {
        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O-'] as $code) {
            $this->bloodType($code);
        }

        BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);

        $this->actingAs($this->officer)
            ->getJson('/api/blood-center/inventory/thresholds')
            ->assertOk()
            ->assertJsonPath('facility.id', $this->centre->id)
            ->assertJsonPath('facility.type', 'blood_center')
            ->assertJsonCount(8, 'blood_types')
            ->assertJsonCount(2, 'components')
            ->assertJsonCount(16, 'cells')
            ->assertJsonPath('totals.monitored', 0)
            ->assertJsonPath('low', []);
    }

    public function test_each_cell_is_judged_against_its_own_minimum(): void
    {
        $aPositive = $this->bloodType('A+');
        $bPositive = $this->bloodType('B+');
        $oNegative = $this->bloodType('O-');

        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 4);
        $this->stockCentre($this->centre, $bPositive, $this->prbc, 5);
        $this->stockCentre($this->centre, $oNegative, $this->prbc, 2);

        StockThreshold::factory()->forCell($this->centre, $this->oPositive, $this->prbc)->minimum(10)->create();
        StockThreshold::factory()->forCell($this->centre, $aPositive, $this->prbc)->minimum(5)->create();
        StockThreshold::factory()->forCell($this->centre, $bPositive, $this->prbc)->minimum(3)->create();

        $response = $this->actingAs($this->officer)
            ->getJson('/api/blood-center/inventory/thresholds')
            ->assertOk();

        $low = $this->cell($response, 'O+', 'Packed RBC');
        $this->assertSame('low', $low['status']);
        $this->assertSame(4, $low['available']);
        $this->assertSame(10, $low['minimum_units']);
        $this->assertSame(6, $low['shortfall']);

        $this->assertSame('critical', $this->cell($response, 'A+', 'Packed RBC')['status']);
        $this->assertSame(5, $this->cell($response, 'A+', 'Packed RBC')['shortfall']);
        $this->assertSame('ok', $this->cell($response, 'B+', 'Packed RBC')['status']);

        // Stock with no minimum says nothing about how well stocked it is.
        $unmonitored = $this->cell($response, 'O-', 'Packed RBC');
        $this->assertSame('unmonitored', $unmonitored['status']);
        $this->assertSame(2, $unmonitored['available']);
        $this->assertNull($unmonitored['minimum_units']);

        $response->assertJsonPath('totals.monitored', 3)
            ->assertJsonPath('totals.ok', 1)
            ->assertJsonPath('totals.low', 1)
            ->assertJsonPath('totals.critical', 1);

        // The shortage list opens with the empty shelf, then the biggest gap.
        $this->assertSame(['A+', 'O+'], array_column($response->json('low'), 'blood_type_code'));
    }

    public function test_only_issuable_stock_is_counted(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 1, ['status' => BloodUnitStatus::Quarantined]);
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 1, ['status' => BloodUnitStatus::Reserved]);
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 1, ['status' => BloodUnitStatus::Expired]);
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 1, ['status' => BloodUnitStatus::Discarded]);

        // Past its date but not yet swept: still says `available`, cannot be issued.
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 1, ['expiry_date' => now()->subDay()->toDateString()]);

        // Expires today: may be issued until the day ends.
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 1, ['expiry_date' => OperationalDay::todayAsDate()]);

        $response = $this->actingAs($this->officer)->getJson('/api/blood-center/inventory/thresholds')->assertOk();

        $this->assertSame(3, $this->cell($response, 'O+', 'Packed RBC')['available']);
    }

    public function test_another_facilitys_stock_and_thresholds_are_not_visible(): void
    {
        $other = Facility::factory()->approved()->create();

        $this->stockCentre($other, $this->oPositive, $this->prbc, 9);
        StockThreshold::factory()->forCell($other, $this->oPositive, $this->prbc)->minimum(20)->create();

        $response = $this->actingAs($this->officer)->getJson('/api/blood-center/inventory/thresholds')->assertOk();

        $cell = $this->cell($response, 'O+', 'Packed RBC');
        $this->assertSame(0, $cell['available']);
        $this->assertNull($cell['minimum_units']);
        $this->assertSame('unmonitored', $cell['status']);
    }

    public function test_an_inventory_control_officer_can_set_a_threshold(): void
    {
        $response = $this->actingAs($this->officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $this->prbc, 12)]])
            ->assertOk();

        $this->assertSame(12, $this->cell($response, 'O+', 'Packed RBC')['minimum_units']);

        $this->assertDatabaseHas('stock_thresholds', [
            'facility_id' => $this->centre->id,
            'blood_type_id' => $this->oPositive->id,
            'component_id' => $this->prbc->id,
            'minimum_units' => 12,
            'alerts_enabled' => true,
            'updated_by' => $this->officer->id,
        ]);

        $audit = AuditLog::query()->where('action', 'stock_threshold.saved')->sole();
        $this->assertSame($this->officer->id, $audit->actor_id);
        $this->assertSame($this->centre->id, $audit->context['facility_id']);
        $this->assertNull($audit->context['minimum_units_before']);
        $this->assertSame(12, $audit->context['minimum_units_after']);
    }

    public function test_the_center_admin_can_set_a_threshold(): void
    {
        $this->actingAs($this->supervisor)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $this->prbc, 7)]])
            ->assertOk();

        $this->assertDatabaseHas('stock_thresholds', ['facility_id' => $this->centre->id, 'minimum_units' => 7]);
    }

    public function test_a_dispatch_coordinator_can_read_but_not_edit(): void
    {
        $dispatcher = User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create();

        $this->actingAs($dispatcher)->getJson('/api/blood-center/inventory/thresholds')->assertOk();

        $this->actingAs($dispatcher)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $this->prbc, 5)]])
            ->assertForbidden();

        $this->assertDatabaseCount('stock_thresholds', 0);
    }

    public function test_a_custom_issuance_role_can_read_but_never_inherits_editing(): void
    {
        $custom = User::factory()->bloodCenterCustomStaff($this->centre, Department::Issuance)->create();

        $this->actingAs($custom)->getJson('/api/blood-center/inventory/thresholds')->assertOk();

        $this->actingAs($custom)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $this->prbc, 5)]])
            ->assertForbidden();
    }

    public function test_staff_who_cannot_see_the_inventory_cannot_read_thresholds(): void
    {
        $billing = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();

        $this->actingAs($billing)->getJson('/api/blood-center/inventory/thresholds')->assertForbidden();
    }

    public function test_saving_twice_upserts_one_row_and_skips_what_did_not_change(): void
    {
        $body = ['thresholds' => [$this->payload('O+', $this->prbc, 12)]];

        $this->actingAs($this->officer)->putJson('/api/blood-center/inventory/thresholds', $body)->assertOk();
        $this->actingAs($this->officer)->putJson('/api/blood-center/inventory/thresholds', $body)->assertOk();

        $this->assertDatabaseCount('stock_thresholds', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'stock_threshold.saved')->count());

        $body['thresholds'][0]['minimum_units'] = 15;
        $this->actingAs($this->officer)->putJson('/api/blood-center/inventory/thresholds', $body)->assertOk();

        $this->assertDatabaseCount('stock_thresholds', 1);
        $this->assertDatabaseHas('stock_thresholds', ['minimum_units' => 15]);
        $this->assertSame(2, AuditLog::query()->where('action', 'stock_threshold.saved')->count());
    }

    public function test_a_null_minimum_clears_the_threshold(): void
    {
        StockThreshold::factory()->forCell($this->centre, $this->oPositive, $this->prbc)->minimum(10)->create();

        $this->actingAs($this->officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $this->prbc, null)]])
            ->assertOk();

        $this->assertDatabaseCount('stock_thresholds', 0);

        $audit = AuditLog::query()->where('action', 'stock_threshold.cleared')->sole();
        $this->assertSame(10, $audit->context['minimum_units_before']);
        $this->assertSame('cleared_by_staff', $audit->context['reason']);
    }

    public function test_clearing_a_cell_that_has_no_threshold_writes_nothing(): void
    {
        $this->actingAs($this->officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $this->prbc, null)]])
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'stock_threshold.%')->count());
    }

    public function test_alerts_default_on_and_can_be_switched_off_for_one_cell(): void
    {
        $this->actingAs($this->officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [
                $this->payload('O+', $this->prbc, 10, false),
            ]])
            ->assertOk();

        $this->assertDatabaseHas('stock_thresholds', ['minimum_units' => 10, 'alerts_enabled' => false]);
    }

    public function test_a_save_cannot_touch_another_facilitys_thresholds(): void
    {
        $other = Facility::factory()->approved()->create();
        StockThreshold::factory()->forCell($other, $this->oPositive, $this->prbc)->minimum(20)->create();

        $this->actingAs($this->officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $this->prbc, 5)]])
            ->assertOk();

        $this->assertDatabaseHas('stock_thresholds', ['facility_id' => $other->id, 'minimum_units' => 20]);
        $this->assertDatabaseHas('stock_thresholds', ['facility_id' => $this->centre->id, 'minimum_units' => 5]);
    }

    public function test_validation_refuses_bad_payloads(): void
    {
        $valid = $this->payload('O+', $this->prbc, 5);

        $cases = [
            'empty list' => ['thresholds' => []],
            'missing list' => [],
            'minimum below one' => ['thresholds' => [[...$valid, 'minimum_units' => 0]]],
            'minimum too large' => ['thresholds' => [[...$valid, 'minimum_units' => 10000]]],
            'minimum not a number' => ['thresholds' => [[...$valid, 'minimum_units' => 'lots']]],
            'minimum missing' => ['thresholds' => [['blood_type_id' => $valid['blood_type_id'], 'component_id' => $valid['component_id']]]],
            'unknown blood type' => ['thresholds' => [[...$valid, 'blood_type_id' => 9999]]],
            'unknown component' => ['thresholds' => [[...$valid, 'component_id' => 9999]]],
            'duplicate cell' => ['thresholds' => [$valid, $valid]],
        ];

        foreach ($cases as $name => $body) {
            $this->actingAs($this->officer)
                ->putJson('/api/blood-center/inventory/thresholds', $body)
                ->assertUnprocessable($name);
        }

        $this->assertDatabaseCount('stock_thresholds', 0);
    }

    public function test_a_retired_component_cannot_be_given_a_threshold(): void
    {
        $retired = BloodComponent::factory()->create(['name' => 'Retired Product']);
        $retired->delete();

        $this->actingAs($this->officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [$this->payload('O+', $retired, 5)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('thresholds.0.component_id');

        $this->assertDatabaseCount('stock_thresholds', 0);
    }

    /**
     * @return array{blood_type_id: int, component_id: int, minimum_units: int|null, alerts_enabled?: bool}
     */
    private function payload(string $bloodTypeCode, BloodComponent $component, ?int $minimum, ?bool $alertsEnabled = null): array
    {
        $cell = [
            'blood_type_id' => $this->bloodType($bloodTypeCode)->id,
            'component_id' => $component->id,
            'minimum_units' => $minimum,
        ];

        if ($alertsEnabled !== null) {
            $cell['alerts_enabled'] = $alertsEnabled;
        }

        return $cell;
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return array<string, mixed>
     */
    private function cell(TestResponse $response, string $bloodTypeCode, string $componentName): array
    {
        $cell = collect($response->json('cells'))->first(
            fn (array $cell): bool => $cell['blood_type_code'] === $bloodTypeCode && $cell['component_name'] === $componentName
        );

        $this->assertNotNull($cell, "No {$bloodTypeCode} {$componentName} cell in the response.");

        return $cell;
    }
}
