<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalUnitStatus;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * Receiving in two steps (project owner, 2026-10-11): a bag whose arrival was
 * confirmed waits in custody until inventory staff stock it in.
 *
 * What is locked in: an arrived bag is not stock — it cannot be tagged and
 * counts in no stock figure — until it is stocked in; stocking in makes it
 * available and records where it is stored (optional), when and by whom; a
 * bag that is not this blood bank's, not awaiting stock-in, or past its date
 * refuses the lot; the stock-in form is offered the configured locations and
 * those already used; and an arrived bag can still be discarded or expire.
 */
class HospitalStockInTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        // 10:00 in Manila, so the UTC and operational dates agree.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00'));
    }

    public function test_an_arrived_bag_is_not_stock_until_it_is_stocked_in(): void
    {
        $unit = $this->receiveUnstocked()->first();

        $this->assertSame(HospitalUnitStatus::Received, $unit->status);
        $this->tagUnit($unit)->assertStatus(409)->assertJsonPath('code', 'unit_not_available');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/summary')
            ->assertOk()
            ->assertJsonPath('totals.received', 1)
            ->assertJsonPath('totals.available', 0)
            ->assertJsonCount(0, 'by_blood_type');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?status=received')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status_label', 'Awaiting stock-in')
            ->assertJsonPath('data.0.stocked_at', null);
    }

    public function test_stocking_in_makes_bags_available_where_staff_put_them(): void
    {
        [$first, $second] = $this->receiveUnstocked(2);

        $this->stockIn([
            ['unit_id' => $first->unit_id, 'storage_location' => '  Ref 1 '],
            ['unit_id' => $second->unit_id, 'storage_location' => 'Freezer A'],
        ])
            ->assertOk()
            ->assertJsonPath('stocked_count', 2)
            ->assertJsonPath('units.0.status', HospitalUnitStatus::Available->value)
            ->assertJsonPath('units.0.storage_location', 'Ref 1')
            ->assertJsonPath('units.1.storage_location', 'Freezer A')
            ->assertJsonPath('units.0.stocked_by', trim($this->requester->first_name.' '.$this->requester->last_name));

        $first->refresh();
        $this->assertSame(HospitalUnitStatus::Available, $first->status);
        $this->assertSame('Ref 1', $first->storage_location);
        $this->assertSame($this->requester->id, $first->stocked_by);
        $this->assertTrue($first->stocked_at->equalTo(now()->startOfSecond()));

        $this->tagUnit($first)->assertOk();

        $row = AuditLog::query()->where('action', 'hospital_inventory.stocked')->where('auditable_id', $first->unit_id)->sole();
        $this->assertSame(['received', 'available', 'Ref 1'], [
            $row->context['previous_status'], $row->context['new_status'], $row->context['storage_location'],
        ]);
    }

    public function test_a_bag_not_awaiting_stock_in_refuses_the_lot(): void
    {
        $shelved = $this->receiveOne();
        $waiting = $this->receiveUnstocked()->first();

        $this->stockIn([
            ['unit_id' => $waiting->unit_id, 'storage_location' => 'Ref 1'],
            ['unit_id' => $shelved->unit_id, 'storage_location' => 'Ref 1'],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_awaiting_stock_in');

        $this->assertSame(HospitalUnitStatus::Received, $waiting->fresh()->status);
    }

    public function test_another_blood_banks_bag_is_not_found(): void
    {
        $waiting = $this->receiveUnstocked()->first();
        $elsewhere = User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->create();

        $this->actingAs($elsewhere)
            ->postJson('/api/hospital/inventory/stock-in', ['units' => [['unit_id' => $waiting->unit_id, 'storage_location' => 'Ref 1']]])
            ->assertNotFound()
            ->assertJsonPath('code', 'unit_not_found');

        $this->assertSame(HospitalUnitStatus::Received, $waiting->fresh()->status);
    }

    public function test_a_bag_past_its_date_is_discarded_not_stocked_in(): void
    {
        $unit = $this->receiveUnstocked(1, expiresInDays: 1)->first();

        // The day after its expiry, before the nightly sweep has run.
        $this->travelTo(CarbonImmutable::parse('2026-10-04 02:00:00'));

        $this->stockIn([['unit_id' => $unit->unit_id, 'storage_location' => 'Ref 1']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'bag_expired');

        $this->act($unit, 'discard', ['reason' => 'Arrived past its date'])
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Discarded->value);
    }

    public function test_the_sweep_expires_an_arrived_bag_past_its_date(): void
    {
        $unit = $this->receiveUnstocked(1, expiresInDays: 1)->first();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 02:00:00'));
        $this->artisan('hospital:expire-units')->assertSuccessful();

        $this->assertSame(HospitalUnitStatus::Expired, $unit->fresh()->status);
        $this->assertSame('received', AuditLog::query()->where('action', 'hospital_inventory.expired')->sole()->context['previous_status']);
    }

    public function test_the_form_is_offered_the_configured_locations_and_those_already_used(): void
    {
        $unit = $this->receiveUnstocked()->first();
        $this->stockIn([['unit_id' => $unit->unit_id, 'storage_location' => 'Blood Bank Ref 2']])->assertOk();

        $locations = $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/summary')
            ->assertOk()
            ->json('storage_locations');

        $this->assertContains('Blood Bank Ref 2', $locations);
        $this->assertContains(config('blood_center.storage_locations')[0], $locations);
    }

    public function test_the_request_names_each_bag_once(): void
    {
        $unit = $this->receiveUnstocked()->first();

        $this->stockIn([])->assertJsonValidationErrors('units');
        $this->stockIn([
            ['unit_id' => $unit->unit_id, 'storage_location' => 'Ref 1'],
            ['unit_id' => $unit->unit_id, 'storage_location' => 'Ref 1'],
        ])
            ->assertJsonValidationErrors(['units.0.unit_id' => 'A bag is listed twice.']);
        $this->stockIn([['unit_id' => $unit->unit_id, 'storage_location' => str_repeat('x', 101)]])
            ->assertJsonValidationErrors('units.0.storage_location');
    }

    public function test_every_bag_needs_a_storage_location(): void
    {
        $unit = $this->receiveUnstocked()->first();

        foreach ([['unit_id' => $unit->unit_id], ['unit_id' => $unit->unit_id, 'storage_location' => '   ']] as $bag) {
            $this->stockIn([$bag])
                ->assertJsonValidationErrors(['units.0.storage_location' => 'Every bag needs a storage location.']);
        }

        $this->assertSame(HospitalUnitStatus::Received, $unit->fresh()->status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $units
     */
    private function stockIn(array $units): TestResponse
    {
        return $this->actingAs($this->requester)->postJson('/api/hospital/inventory/stock-in', ['units' => $units]);
    }
}
