<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\AuditLog;
use App\Models\HospitalUnit;
use App\Models\UnitTag;
use App\Repository\HospitalInventoryRepository;
use App\Service\HospitalInventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CompilesPostgresQueries;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * The tag sweep must never write on the strength of its own selection.
 *
 * Between the sweep choosing a lapsed tag and untagging it, a member of staff
 * may release it themselves. Their decision, their reason and their name must
 * stand, and no audit row may claim the scheduler ended a tag it did not.
 * As with ExpirySweepRaceTest, the lock is proven against the pgsql grammar
 * and the predicates against sqlite, where the lock compiles to nothing.
 */
class HospitalTagSweepRaceTest extends TestCase
{
    use BuildsHospitalStock, CompilesPostgresQueries, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00'));
    }

    public function test_every_hospital_write_and_the_sweep_ask_for_row_locks(): void
    {
        $repository = app(HospitalInventoryRepository::class);
        $now = CarbonImmutable::now();

        foreach ([
            fn () => $repository->lockUnitForFacility('BAG-01', 1),
            fn () => $repository->lockActiveTag(1),
            fn () => $repository->lockLatestTag(1),
            fn () => $repository->lockUnitsById([1, 2]),
            fn () => $repository->lockConfirmedOverdueTags([1, 2], $now),
            fn () => $repository->lockConfirmedDueUnits([1, 2], '2026-10-02'),
        ] as $query) {
            $this->assertStringEndsWith('for update', $this->compiledOnPostgres($query)[0]['query']);
        }
    }

    public function test_the_confirmation_query_drops_a_tag_whose_deadline_moved_on(): void
    {
        [$lapsed, $crossmatched] = $this->receiveStock(2);
        $this->tagUnit($lapsed)->assertOk();
        $this->tagUnit($crossmatched)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 07:00:00'));
        $this->act($crossmatched, 'crossmatch')->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));

        $confirmed = app(HospitalInventoryRepository::class)->lockConfirmedOverdueTags(
            UnitTag::query()->pluck('id')->all(),
            CarbonImmutable::now(),
        );

        // The crossmatched tag runs against its new, later deadline now.
        $this->assertSame([$lapsed->id], $confirmed->pluck('hospital_unit_id')->all());
    }

    public function test_the_untag_statement_carries_the_same_predicates_as_the_lock(): void
    {
        [$lapsed, $released] = $this->receiveStock(2);
        $this->tagUnit($lapsed)->assertOk();
        $this->tagUnit($released)->assertOk();
        $this->act($released, 'release', ['reason' => 'Patient discharged'])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));

        // Handed every id, confirmed or not: the statement defends itself.
        $affected = app(HospitalInventoryRepository::class)->markTagsUntagged(
            UnitTag::query()->pluck('id')->all(),
            UnitTagStatus::TagAssigned,
            CarbonImmutable::now(),
        );

        $this->assertSame(1, $affected);
        $this->assertSame(
            UntagReason::ReleasedByStaff,
            UnitTag::query()->where('hospital_unit_id', $released->id)->sole()->untag_reason
        );
    }

    public function test_a_staff_release_inside_the_window_is_not_overwritten_by_the_sweep(): void
    {
        [$first, $victim, $third] = $this->receiveStock(3);

        foreach ([$first, $victim, $third] as $unit) {
            $this->tagUnit($unit)->assertOk();
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00:00'));

        $this->whenTheSweepSelectsCandidates(function () use ($victim): void {
            app(HospitalInventoryService::class)->release($this->requester, $victim->unit_id, 'Crossmatch incompatible');
        });

        $this->artisan('hospital:expire-tags')->assertSuccessful();

        $tag = UnitTag::query()->where('hospital_unit_id', $victim->id)->sole();

        $this->assertSame(UnitTagStatus::UntaggedAssigned, $tag->status);
        $this->assertSame(UntagReason::ReleasedByStaff, $tag->untag_reason);
        $this->assertSame('Crossmatch incompatible', $tag->untag_note);
        $this->assertSame($this->requester->id, $tag->untagged_by);

        $machineRows = AuditLog::query()
            ->where('action', 'hospital_inventory.untagged')
            ->whereNull('actor_id')
            ->get();

        $this->assertEqualsCanonicalizing([$first->unit_id, $third->unit_id], $machineRows->pluck('auditable_id')->all());
        $this->assertSame(2, AuditLog::query()->where('action', 'hospital_inventory.tag_sweep')->sole()->context['untagged_count']);

        foreach ([$first, $victim, $third] as $unit) {
            $this->assertSame(HospitalUnitStatus::Available, $unit->fresh()->status);
        }
    }

    public function test_the_database_refuses_a_second_active_tag_on_one_bag(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->expectException(QueryException::class);

        UnitTag::factory()->forUnit(HospitalUnit::query()->findOrFail($unit->id))->create();
    }

    /**
     * Run a staff write in the gap between the sweep's candidate select and its locked re-read.
     */
    private function whenTheSweepSelectsCandidates(callable $staffAction): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $staffAction): void {
            if ($fired || ! (str_contains($query->sql, 'from "unit_tags"') && str_contains($query->sql, 'limit 500'))) {
                return;
            }

            $fired = true;

            $staffAction();
        });
    }
}
