<?php

namespace Tests\Feature\StockThreshold;

use App\Enums\HospitalUnitStatus;
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
 * A hospital blood bank's minimum stock per blood type and component.
 *
 * The same table and the same endpoints as the centre's, counting the bank's
 * own shelf instead: only bags in `available` custody. A blood bank has no
 * departments or abilities, so any of its accounts may read and set minimums.
 */
class HospitalStockThresholdTest extends TestCase
{
    use BuildsThresholdStock, LazilyRefreshDatabase;

    private Facility $hospital;

    private User $staff;

    private BloodType $oPositive;

    private BloodComponent $prbc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->staff = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->oPositive = $this->bloodType('O+');
        $this->prbc = BloodComponent::factory()->create(['name' => 'Packed RBC']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/hospital/inventory/thresholds')->assertUnauthorized();
        $this->putJson('/api/hospital/inventory/thresholds', [])->assertUnauthorized();
    }

    public function test_status_describes_a_blood_bank(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/hospital/inventory/thresholds')
            ->assertOk()
            ->assertJsonPath('facility.id', $this->hospital->id)
            ->assertJsonPath('facility.type', 'blood_bank')
            ->assertJsonCount(1, 'blood_types')
            ->assertJsonCount(1, 'components')
            ->assertJsonCount(1, 'cells');
    }

    public function test_only_bags_in_available_custody_are_counted(): void
    {
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 2);

        // Held for a patient, out of storage, spent, or gone: none of it is stock.
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, HospitalUnitStatus::TagAssigned);
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, HospitalUnitStatus::TagCrossmatched);
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, HospitalUnitStatus::PendingReturn);
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, HospitalUnitStatus::Transfused);
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, HospitalUnitStatus::Expired);
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, HospitalUnitStatus::Discarded);

        // Available, but past its date and not yet swept.
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, unitOverrides: ['expiry_date' => now()->subDay()->toDateString()]);

        // Available and expiring today: still issuable.
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 1, unitOverrides: ['expiry_date' => OperationalDay::todayAsDate()]);

        $response = $this->actingAs($this->staff)->getJson('/api/hospital/inventory/thresholds')->assertOk();

        $this->assertSame(3, $this->onlyCell($response)['available']);
    }

    public function test_a_hospital_is_judged_against_its_own_minimum(): void
    {
        $this->stockHospital($this->hospital, $this->oPositive, $this->prbc, 2);
        StockThreshold::factory()->forCell($this->hospital, $this->oPositive, $this->prbc)->minimum(5)->create();

        $response = $this->actingAs($this->staff)->getJson('/api/hospital/inventory/thresholds')->assertOk();

        $cell = $this->onlyCell($response);
        $this->assertSame('low', $cell['status']);
        $this->assertSame(3, $cell['shortfall']);
        $response->assertJsonPath('low.0.blood_type_code', 'O+');
    }

    public function test_another_hospitals_shelf_and_thresholds_are_not_visible(): void
    {
        $other = Facility::factory()->bloodBank()->approved()->create();

        $this->stockHospital($other, $this->oPositive, $this->prbc, 6);
        StockThreshold::factory()->forCell($other, $this->oPositive, $this->prbc)->minimum(30)->create();

        $response = $this->actingAs($this->staff)->getJson('/api/hospital/inventory/thresholds')->assertOk();

        $cell = $this->onlyCell($response);
        $this->assertSame(0, $cell['available']);
        $this->assertNull($cell['minimum_units']);
    }

    public function test_a_centres_own_shelf_is_never_counted_for_a_hospital(): void
    {
        $centre = Facility::factory()->approved()->create();
        $this->stockCentre($centre, $this->oPositive, $this->prbc, 7);

        $response = $this->actingAs($this->staff)->getJson('/api/hospital/inventory/thresholds')->assertOk();

        $this->assertSame(0, $this->onlyCell($response)['available']);
    }

    public function test_any_blood_bank_account_can_set_a_threshold_and_a_colleague_sees_it(): void
    {
        $colleague = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->actingAs($this->staff)
            ->putJson('/api/hospital/inventory/thresholds', ['thresholds' => [[
                'blood_type_id' => $this->oPositive->id,
                'component_id' => $this->prbc->id,
                'minimum_units' => 8,
            ]]])
            ->assertOk();

        $this->assertDatabaseHas('stock_thresholds', [
            'facility_id' => $this->hospital->id,
            'minimum_units' => 8,
            'updated_by' => $this->staff->id,
        ]);

        $response = $this->actingAs($colleague)->getJson('/api/hospital/inventory/thresholds')->assertOk();
        $this->assertSame(8, $this->onlyCell($response)['minimum_units']);

        $this->assertSame($this->hospital->id, AuditLog::query()->where('action', 'stock_threshold.saved')->sole()->context['facility_id']);
    }

    public function test_the_two_portals_are_closed_to_each_others_accounts(): void
    {
        $centreStaff = User::factory()->bloodCenterStaff()->create(['is_supervisor' => true]);

        $this->actingAs($centreStaff)->getJson('/api/hospital/inventory/thresholds')->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/blood-center/inventory/thresholds')->assertForbidden();
        $this->actingAs($this->staff)->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => []])->assertForbidden();
    }

    public function test_the_route_is_not_swallowed_by_the_bag_number_route(): void
    {
        // /{unit} matches any string, so an undeclared-before route would be
        // answered as "unit not found" instead.
        $this->actingAs($this->staff)
            ->getJson('/api/hospital/inventory/thresholds')
            ->assertOk()
            ->assertJsonMissingPath('code');
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return array<string, mixed>
     */
    private function onlyCell(TestResponse $response): array
    {
        $cells = $response->json('cells');
        $this->assertCount(1, $cells);

        return $cells[0];
    }
}
