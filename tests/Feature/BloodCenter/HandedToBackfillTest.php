<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\StaffRole;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Copying who took each dispatched unit from the history onto its allocation.
 *
 * Before allocations carried handed_to, release() wrote the name only into the
 * released event's meta. The backfill fills the column for the allocations
 * those events released, where the match is certain, and leaves the rest null:
 * the event stays the record, and the timeline still shows the name.
 */
class HandedToBackfillTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $head;

    private BloodRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $centre = Facility::factory()->approved()->create();
        $this->head = User::factory()->bloodCenterStaff($centre, StaffRole::InventoryControlOfficer)->create();

        $this->request = BloodRequest::factory()
            ->raisedBy(Facility::factory()->bloodBank()->approved()->create())
            ->addressedTo($centre)
            ->create();
    }

    private function backfill(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_08_100003_backfill_handed_to_on_released_allocations.php');

        $migration->up();
    }

    /**
     * Model a database that does not enforce one claimed allocation per unit.
     *
     * PostgreSQL and SQLite do, through request_allocations_claimed_unit_unique,
     * which counts a released hold as claiming: a unit cannot have two released
     * allocations there. MySQL skips that index, and data from before it
     * existed may hold more than one, so the backfill's tie-breaking is
     * defensive and is only reachable once the index is out of the way.
     */
    private function withoutTheClaimedUnitIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS request_allocations_claimed_unit_unique');
    }

    private function allocation(string $unitId, ?Carbon $releasedAt = null, array $overrides = []): RequestAllocation
    {
        BloodUnit::query()->find($unitId)
            ?? BloodUnit::factory()->create(['id' => $unitId, 'status' => BloodUnitStatus::Issued]);

        return RequestAllocation::factory()
            ->released($this->head)
            ->create([
                'request_id' => $this->request->id,
                'unit_id' => $unitId,
                'released_at' => $releasedAt ?? now(),
                ...$overrides,
            ]);
    }

    /**
     * @param  array<int, string>  $unitIds
     * @param  array<string, mixed>|null  $meta
     */
    private function releasedEvent(array $unitIds, ?array $meta, ?Carbon $at = null): void
    {
        $event = new BloodRequestEvent([
            'request_id' => $this->request->id,
            'event' => 'released',
            'lines' => [],
            'unit_ids' => $unitIds,
            'meta' => $meta,
        ]);
        $event->created_at = $at ?? now();
        $event->save();
    }

    public function test_a_single_released_allocation_gets_the_name_from_its_event(): void
    {
        Log::spy();

        $allocation = $this->allocation('UNIT-1');
        $this->releasedEvent(['UNIT-1'], ['handed_to' => ' Pedro Cruz ']);

        $this->backfill();

        $this->assertSame('Pedro Cruz', $allocation->refresh()->handed_to);

        Log::shouldHaveReceived('info')->once()->with('handed_to backfill', \Mockery::on(
            fn (array $context): bool => $context['filled'] === 1 && $context['ambiguous'] === 0 && $context['unmatched'] === 0
        ));
    }

    public function test_one_event_fills_every_unit_it_released(): void
    {
        $first = $this->allocation('UNIT-1');
        $second = $this->allocation('UNIT-2');
        $this->releasedEvent(['UNIT-1', 'UNIT-2'], ['handed_to' => 'Maria Santos']);

        $this->backfill();

        $this->assertSame('Maria Santos', $first->refresh()->handed_to);
        $this->assertSame('Maria Santos', $second->refresh()->handed_to);
    }

    public function test_a_unit_released_to_the_same_request_twice_fills_only_the_allocation_nearest_the_event(): void
    {
        $this->withoutTheClaimedUnitIndex();

        $at = now()->startOfSecond();

        $near = $this->allocation('UNIT-1', $at->copy()->addSecond());

        // A second, older release of the same bag to the same request: only
        // possible in legacy data, and nowhere near this event.
        $old = $this->allocation('UNIT-1', $at->copy()->subDays(3));

        $this->releasedEvent(['UNIT-1'], ['handed_to' => 'Pedro Cruz'], $at);

        $this->backfill();

        $this->assertSame('Pedro Cruz', $near->refresh()->handed_to);
        $this->assertNull($old->refresh()->handed_to);
    }

    public function test_equally_close_candidates_are_left_null_and_logged_as_ambiguous(): void
    {
        Log::spy();
        $this->withoutTheClaimedUnitIndex();

        $at = now()->startOfSecond();
        $a = $this->allocation('UNIT-1', $at->copy()->subSeconds(2));
        $b = $this->allocation('UNIT-1', $at->copy()->addSeconds(2));
        $this->releasedEvent(['UNIT-1'], ['handed_to' => 'Pedro Cruz'], $at);

        $this->backfill();

        $this->assertNull($a->refresh()->handed_to);
        $this->assertNull($b->refresh()->handed_to);

        Log::shouldHaveReceived('info')->once()->with('handed_to backfill', \Mockery::on(
            fn (array $context): bool => $context['filled'] === 0
                && $context['ambiguous'] === 2
                && $context['ambiguous_allocation_ids'] === [$a->id, $b->id]
        ));
    }

    public function test_candidates_outside_the_window_are_left_null(): void
    {
        $this->withoutTheClaimedUnitIndex();

        $at = now()->startOfSecond();
        $a = $this->allocation('UNIT-1', $at->copy()->subMinutes(10));
        $b = $this->allocation('UNIT-1', $at->copy()->subMinutes(20));
        $this->releasedEvent(['UNIT-1'], ['handed_to' => 'Pedro Cruz'], $at);

        $this->backfill();

        $this->assertNull($a->refresh()->handed_to);
        $this->assertNull($b->refresh()->handed_to);
    }

    public function test_an_event_without_a_name_is_skipped(): void
    {
        $withMeta = $this->allocation('UNIT-1');
        $blank = $this->allocation('UNIT-2');
        $this->releasedEvent(['UNIT-1'], ['something_else' => true]);
        $this->releasedEvent(['UNIT-2'], ['handed_to' => '   ']);

        $this->backfill();

        $this->assertNull($withMeta->refresh()->handed_to);
        $this->assertNull($blank->refresh()->handed_to);
    }

    public function test_an_allocation_that_already_has_a_name_is_untouched(): void
    {
        $allocation = $this->allocation('UNIT-1', overrides: ['handed_to' => 'Already Recorded']);
        $this->releasedEvent(['UNIT-1'], ['handed_to' => 'Pedro Cruz']);

        $this->backfill();

        $this->assertSame('Already Recorded', $allocation->refresh()->handed_to);
    }

    public function test_an_event_for_a_unit_with_no_released_allocation_is_counted_as_unmatched(): void
    {
        Log::spy();

        $this->releasedEvent(['GONE-1'], ['handed_to' => 'Pedro Cruz']);

        $this->backfill();

        Log::shouldHaveReceived('info')->once()->with('handed_to backfill', \Mockery::on(
            fn (array $context): bool => $context['unmatched'] === 1 && $context['filled'] === 0
        ));
    }

    public function test_other_events_are_ignored(): void
    {
        $allocation = $this->allocation('UNIT-1');

        $event = new BloodRequestEvent([
            'request_id' => $this->request->id,
            'event' => 'allocated',
            'lines' => [],
            'unit_ids' => ['UNIT-1'],
            'meta' => ['handed_to' => 'Pedro Cruz'],
        ]);
        $event->created_at = now();
        $event->save();

        $this->backfill();

        $this->assertNull($allocation->refresh()->handed_to);
        $this->assertSame(1, DB::table('blood_request_events')->count());
    }
}
