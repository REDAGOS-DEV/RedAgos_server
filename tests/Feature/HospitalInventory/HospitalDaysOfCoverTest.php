<?php

namespace Tests\Feature\HospitalInventory;

use App\Models\HospitalUnit;
use App\Models\UnitTag;
use App\Service\HospitalInventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * Days of cover (project owner, 2026-11-27): how long the blood bank's shelf
 * lasts at its own rate of use over the last four weeks. Projected from its
 * history alone, with no model behind it.
 */
class HospitalDaysOfCoverTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    private function transfuse(HospitalUnit $unit): void
    {
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();
        $this->act($unit, 'transfuse')->assertOk();
    }

    public function test_the_shelf_is_divided_by_the_daily_rate_of_the_last_four_weeks(): void
    {
        [$first, $second] = $this->receiveStock(4)->all();
        $this->transfuse($first);
        $this->transfuse($second);

        $window = HospitalInventoryService::COVER_WINDOW_DAYS;

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/days-of-cover')
            ->assertOk()
            ->assertJsonPath('window_days', $window)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.blood_type.id', $this->bloodType->id)
            ->assertJsonPath('rows.0.component.id', $this->prbc->id)
            ->assertJsonPath('rows.0.available', 2)
            ->assertJsonPath('rows.0.used', 2)
            ->assertJsonPath('rows.0.per_day', round(2 / $window, 2))
            ->assertJsonPath('rows.0.days_left', $window); // JSON sends 28.0 as 28
    }

    public function test_a_transfusion_before_the_window_does_not_count_and_stock_without_use_has_no_days(): void
    {
        [$first] = $this->receiveStock(2)->all();
        $this->transfuse($first);

        UnitTag::query()
            ->where('hospital_unit_id', $first->id)
            ->update(['transfused_at' => now()->subDays(HospitalInventoryService::COVER_WINDOW_DAYS + 1)]);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/days-of-cover')
            ->assertOk()
            ->assertJsonPath('rows.0.available', 1)
            ->assertJsonPath('rows.0.used', 0)
            ->assertJsonPath('rows.0.days_left', null);
    }

    public function test_an_empty_shelf_with_no_use_lists_nothing(): void
    {
        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/days-of-cover')
            ->assertOk()
            ->assertJsonPath('rows', []);
    }
}
