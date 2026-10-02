<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\BloodUnitStatus;
use App\Enums\HospitalUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodUnit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * Past-expiry bags on a hospital's own shelf, and the boundary with the centre's sweep.
 *
 * Two sweeps, two tables, and neither may reach into the other's: the centre's
 * reads blood_units, where a received bag still says `issued`; the hospital's
 * reads hospital_units and never writes blood_units.
 */
class HospitalUnitExpiryTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        // 10:00 in Manila, so the UTC and operational dates agree.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00'));
    }

    public function test_an_available_bag_past_its_date_is_expired_and_a_tagged_one_is_left_alone(): void
    {
        [$shelf, $tagged] = $this->receiveStock(2, expiresInDays: 1);
        $fresh = $this->receiveOne(expiresInDays: 10);
        $this->tagUnit($tagged)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 02:00:00'));

        $this->artisan('hospital:expire-units')->assertSuccessful();

        $this->assertSame(HospitalUnitStatus::Expired, $shelf->fresh()->status);
        $this->assertNotNull($shelf->fresh()->expired_at);
        $this->assertSame(HospitalUnitStatus::TagAssigned, $tagged->fresh()->status);
        $this->assertSame(HospitalUnitStatus::Available, $fresh->fresh()->status);

        $row = AuditLog::query()->where('action', 'hospital_inventory.expired')->sole();
        $this->assertSame($shelf->unit_id, $row->auditable_id);
        $this->assertNull($row->actor_id);

        $this->assertSame(1, AuditLog::query()->where('action', 'hospital_inventory.expiry_swept')->sole()->context['expired_count']);
    }

    public function test_an_expired_bag_can_be_discarded_but_never_tagged(): void
    {
        $unit = $this->receiveOne(expiresInDays: 1);

        $this->travelTo(CarbonImmutable::parse('2026-10-04 02:00:00'));
        $this->artisan('hospital:expire-units')->assertSuccessful();

        $this->tagUnit($unit)->assertStatus(409)->assertJsonPath('code', 'unit_not_available');

        $this->act($unit, 'discard', ['reason' => 'Expired on the shelf'])
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Discarded->value);
    }

    public function test_the_hospital_sweep_never_writes_the_centres_row(): void
    {
        $unit = $this->receiveOne(expiresInDays: 1);
        $bag = BloodUnit::query()->findOrFail($unit->unit_id);

        $this->travelTo(CarbonImmutable::parse('2026-10-04 02:00:00'));
        $this->artisan('hospital:expire-units')->assertSuccessful();

        $after = $bag->fresh();
        $this->assertSame(BloodUnitStatus::Issued, $after->status);
        $this->assertNull($after->expired_at);
        $this->assertEquals($bag->updated_at, $after->updated_at);
    }

    public function test_the_centre_sweep_leaves_hospital_stock_alone(): void
    {
        $unit = $this->receiveOne(expiresInDays: 1);

        $this->travelTo(CarbonImmutable::parse('2026-10-04 02:00:00'));
        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(HospitalUnitStatus::Available, $unit->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'hospital_inventory.expired')->count());
    }

    public function test_a_run_that_expires_nothing_still_writes_its_run_row(): void
    {
        $this->artisan('hospital:expire-units')->assertSuccessful();

        $row = AuditLog::query()->where('action', 'hospital_inventory.expiry_swept')->sole();

        $this->assertSame(0, $row->context['expired_count']);
        $this->assertSame('schedule:hospital:expire-units', $row->context['source']);
    }

    public function test_a_bag_expiring_today_is_still_stock_until_the_day_ends(): void
    {
        $unit = $this->receiveOne(expiresInDays: 0);

        $this->travelTo(CarbonImmutable::parse('2026-10-02 15:30:00')); // 23:30 Manila
        $this->artisan('hospital:expire-units')->assertSuccessful();

        $this->assertSame(HospitalUnitStatus::Available, $unit->fresh()->status);
        $this->tagUnit($unit)->assertOk();
    }
}
