<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\AuditLog;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\HospitalUnit;
use App\Models\UnitTag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * The two 24-hour periods, to the second, and the sweep that ends them.
 *
 * A tag placed at 08:00 has until 07:59:59 the next day: at 08:00:00 it has
 * lapsed. Staff writes refuse it from that second whether or not the sweep has
 * run, and the sweep untags it from that second — so the boundary is the same
 * everywhere, and the scheduler's cadence decides only how soon the bag is
 * visibly free.
 */
class HospitalTagDeadlineTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    private HospitalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00'));
        $this->unit = $this->receiveOne();
        $this->tagUnit($this->unit)->assertOk();
    }

    public function test_a_crossmatch_one_second_before_the_deadline_is_accepted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 07:59:59'));

        $this->act($this->unit, 'crossmatch')->assertOk();

        $this->assertSame(HospitalUnitStatus::TagCrossmatched, $this->unit->fresh()->status);
    }

    public function test_a_crossmatch_at_the_deadline_is_refused_even_before_the_sweep_runs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));

        $this->act($this->unit, 'crossmatch')
            ->assertStatus(409)
            ->assertJsonPath('code', 'tag_deadline_passed');

        // Refused, not untagged inline: the sweep is the only system writer.
        $this->assertSame(HospitalUnitStatus::TagAssigned, $this->unit->fresh()->status);
        $this->assertSame(UnitTagStatus::TagAssigned, UnitTag::query()->sole()->status);
    }

    public function test_the_sweep_leaves_a_tag_alone_one_second_before_its_deadline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 07:59:59'));

        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $this->assertSame(HospitalUnitStatus::TagAssigned, $this->unit->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'hospital_inventory.tag_sweep')->count());
    }

    public function test_at_the_crossmatch_deadline_the_sweep_untags_and_returns_the_bag_to_stock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));

        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $tag = UnitTag::query()->sole();

        $this->assertSame(UnitTagStatus::UntaggedAssigned, $tag->status);
        $this->assertSame(UntagReason::CrossmatchDeadlineExpired, $tag->untag_reason);
        $this->assertSame('2026-10-03 08:00:00', $tag->untagged_at->toDateTimeString());
        $this->assertNull($tag->untagged_by, 'The scheduler is nobody.');
        $this->assertSame(HospitalUnitStatus::Available, $this->unit->fresh()->status);

        $row = AuditLog::query()->where('action', 'hospital_inventory.untagged')->sole();
        $this->assertNull($row->actor_id);
        $this->assertSame($this->unit->unit_id, $row->auditable_id);
        $this->assertSame('crossmatch_deadline_expired', $row->context['untag_reason']);
        $this->assertSame('schedule:hospital:expire-tags', $row->context['source']);

        $run = AuditLog::query()->where('action', 'hospital_inventory.tag_sweep')->sole();
        $this->assertSame($row->context['run_id'], $run->context['run_id']);
        $this->assertSame(1, $run->context['by_status']['untagged_assigned']);

        // Free for the next patient, and the lapsed tag stays as history.
        $this->tagUnit($this->unit, ['patient_surname' => 'Reyes'])->assertOk();
        $this->assertSame(2, UnitTag::query()->count());
    }

    public function test_a_transfusion_one_second_before_its_deadline_is_accepted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00'));
        $this->act($this->unit, 'crossmatch')->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:59:59'));

        $this->act($this->unit, 'transfuse')->assertOk();
    }

    public function test_at_the_transfusion_deadline_the_bag_is_refused_and_then_awaits_return(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00'));
        $this->act($this->unit, 'crossmatch')->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00'));

        $this->act($this->unit, 'transfuse')
            ->assertStatus(409)
            ->assertJsonPath('code', 'tag_deadline_passed');

        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $tag = UnitTag::query()->sole();

        $this->assertSame(UnitTagStatus::UntaggedCrossmatched, $tag->status);
        $this->assertSame(UntagReason::TransfusionDeadlineExpired, $tag->untag_reason);
        // It left storage, so it is not stock until somebody says it is back.
        $this->assertSame(HospitalUnitStatus::PendingReturn, $this->unit->fresh()->status);

        $this->act($this->unit, 'return')->assertOk();
        $this->assertSame(HospitalUnitStatus::Available, $this->unit->fresh()->status);
    }

    public function test_a_second_sweep_finds_nothing_left_to_do(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));

        $this->artisan('hospital:expire-tags')->assertSuccessful();
        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $this->assertSame(1, AuditLog::query()->where('action', 'hospital_inventory.untagged')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'hospital_inventory.tag_sweep')->count());
    }

    public function test_the_sweep_covers_every_hospital(): void
    {
        $other = $this->asOtherHospital(function (): HospitalUnit {
            $unit = $this->receiveOne();
            $this->tagUnit($unit)->assertOk();

            return $unit;
        });

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));

        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $this->assertSame(HospitalUnitStatus::Available, $this->unit->fresh()->status);
        $this->assertSame(HospitalUnitStatus::Available, $other->fresh()->status);
        $this->assertCount(2, AuditLog::query()->where('action', 'hospital_inventory.tag_sweep')->sole()->context['by_facility']);
    }

    public function test_the_summary_flags_a_lapsed_tag_the_sweep_has_not_reached(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/summary')
            ->assertOk()
            ->assertJsonPath('overdue_active_tags', 1);

        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/summary')
            ->assertOk()
            ->assertJsonPath('overdue_active_tags', 0);
    }

    public function test_the_list_reports_a_lapsed_tag_as_past_its_deadline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 07:00:00'));

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.active_tag.seconds_remaining', 3600)
            ->assertJsonPath('data.0.active_tag.deadline_passed', false);

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:30'));

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.active_tag.seconds_remaining', 0)
            ->assertJsonPath('data.0.active_tag.deadline_passed', true);
    }

    public function test_tagging_and_untagging_never_touch_the_bags_own_expiry(): void
    {
        $bag = BloodUnit::query()->findOrFail($this->unit->unit_id);

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));
        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $this->tagUnit($this->unit, ['patient_surname' => 'Reyes'])->assertOk();
        $this->act($this->unit, 'crossmatch')->assertOk();
        $this->act($this->unit, 'release', ['reason' => 'Patient discharged'])->assertOk();
        $this->act($this->unit, 'return')->assertOk();

        $after = $bag->fresh();

        $this->assertSame($bag->expiry_date->toDateString(), $after->expiry_date->toDateString());
        $this->assertEquals($bag->updated_at, $after->updated_at);
    }

    /**
     * Run a block as a second hospital blood bank, with its own staff.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asOtherHospital(callable $callback): mixed
    {
        [$hospital, $requester] = [$this->hospital, $this->requester];

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        try {
            return $callback();
        } finally {
            [$this->hospital, $this->requester] = [$hospital, $requester];
        }
    }
}
