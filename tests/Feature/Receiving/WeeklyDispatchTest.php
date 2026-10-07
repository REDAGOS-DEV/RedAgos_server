<?php

namespace Tests\Feature\Receiving;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\HospitalUnitStatus;
use App\Enums\LineClosureReason;
use App\Enums\RequestEventType;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\HospitalUnit;
use App\Models\WeeklyRequest;
use App\Service\FulfillmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\Feature\Receiving\Concerns\SendsWeeklyRequests;
use Tests\TestCase;

/**
 * The centre supplies what it can; a weekly request goes out in one delivery.
 *
 * When a weekly request's blood request is dispatched, whatever the centre
 * did not supply is closed as unavailable in the same transaction — the next
 * request day's order replaces it — so the request ends Partially Fulfilled
 * (Closed) rather than lingering open. A request that is not part of a weekly
 * request is dispatched exactly as before.
 */
class WeeklyDispatchTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase, SendsWeeklyRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelToMonday();
        $this->scheduleWith($this->centre);
    }

    public function test_what_was_not_supplied_is_closed_as_unavailable_when_the_delivery_goes(): void
    {
        $weekly = $this->sentWeekly([[$this->bloodType, $this->prbc, 3], [$this->bloodType, $this->ffp, 2]]);
        $request = $this->requestOf($weekly);

        // The centre holds two packed cells and no plasma.
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('short_by', 3);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value)
            ->assertJsonCount(2, 'closed_short')
            ->assertJsonPath('closed_short.0.component', 'Packed RBC')
            ->assertJsonPath('closed_short.0.quantity', 1)
            ->assertJsonPath('closed_short.1.component', 'Fresh Frozen Plasma')
            ->assertJsonPath('closed_short.1.quantity', 2);

        $request->refresh();
        $this->assertSame(BloodRequestStatus::Partial, $request->status);
        $this->assertNotNull($request->closed_at, 'Every line is supplied or closed, so the request is finished.');

        foreach ($request->items as $item) {
            $this->assertNotNull($item->closed_at);
            $this->assertSame(LineClosureReason::Unavailable, $item->closure_reason);
            $this->assertSame(FulfillmentService::WEEKLY_SHORTFALL_NOTE, $item->closure_note);
            $this->assertSame($this->issuance->id, $item->closed_by);
        }

        $events = BloodRequestEvent::query()->where('request_id', $request->id)->orderBy('id')->pluck('event')->map->value->all();
        $this->assertSame(
            [RequestEventType::Released->value, RequestEventType::LineClosed->value, RequestEventType::LineClosed->value],
            array_slice($events, -3),
            'The delivery is recorded first, then what it left behind.'
        );

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/weekly-requests/{$weekly->id}")
            ->assertOk()
            ->assertJsonPath('weekly_request.status', 'partial')
            ->assertJsonPath('weekly_request.is_open', false)
            ->assertJsonPath('weekly_request.totals.requested', 5)
            ->assertJsonPath('weekly_request.totals.fulfilled', 2)
            ->assertJsonPath('weekly_request.totals.not_supplied', 3)
            ->assertJsonPath('weekly_request.totals.awaiting_receipt', 2)
            ->assertJsonPath('weekly_request.requests.0.is_open', false);
    }

    public function test_a_full_delivery_closes_nothing(): void
    {
        $weekly = $this->sentWeekly([[$this->bloodType, $this->prbc, 2]]);
        $request = $this->requestOf($weekly);
        $this->stock($this->centre, $this->prbc, 2);

        $this->allocateAndRelease($request);

        $request->refresh();
        $this->assertSame(BloodRequestStatus::Fulfilled, $request->status);
        $this->assertNull($request->items()->first()->closed_at);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/weekly-requests/{$weekly->id}")
            ->assertJsonPath('weekly_request.status', 'fulfilled')
            ->assertJsonPath('weekly_request.totals.not_supplied', 0);
    }

    public function test_a_weekly_request_is_not_dispatched_in_part(): void
    {
        $request = $this->requestOf($this->sentWeekly([[$this->bloodType, $this->prbc, 2]]));
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $first = $request->allocations()->orderBy('id')->value('id');

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release", ['allocation_ids' => [$first]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'weekly_release_all');

        $this->assertSame(2, $request->allocations()->where('status', AllocationStatus::Allocated)->count());
        $this->assertNull($request->items()->first()->closed_at);
    }

    public function test_naming_every_reserved_unit_is_dispatching_the_whole_delivery(): void
    {
        $request = $this->requestOf($this->sentWeekly([[$this->bloodType, $this->prbc, 3]]));
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release", [
                'allocation_ids' => $request->allocations()->pluck('id')->all(),
            ])
            ->assertOk()
            ->assertJsonPath('closed_short.0.quantity', 1);
    }

    public function test_a_request_outside_a_weekly_request_is_dispatched_as_before(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $first = $request->allocations()->orderBy('id')->value('id');

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release", ['allocation_ids' => [$first]])
            ->assertOk()
            ->assertJsonPath('closed_short', []);

        $request->refresh();
        $this->assertNull($request->closed_at, 'A request outside a weekly request keeps its remainder open.');
        $this->assertSame(0, $request->items()->whereNotNull('closed_at')->count());
        $this->assertSame(1, $request->allocations()->where('status', AllocationStatus::Allocated)->count());
    }

    public function test_receipt_puts_the_weekly_delivery_on_the_hospitals_shelf(): void
    {
        $request = $this->requestOf($this->sentWeekly([[$this->bloodType, $this->prbc, 3]]));
        $this->stock($this->centre, $this->prbc, 2);
        $this->allocateAndRelease($request);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('stocked_count', 2);

        $this->assertSame(
            2,
            HospitalUnit::query()->where('facility_id', $this->hospital->id)->where('status', HospitalUnitStatus::Available)->count()
        );

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.source.type', 'request')
            ->assertJsonPath('data.0.source.weekly_request_id', $request->weekly_request_id);
    }

    /**
     * The weekly request's only blood request.
     */
    private function requestOf(WeeklyRequest $weekly): BloodRequest
    {
        return BloodRequest::query()->where('weekly_request_id', $weekly->id)->sole();
    }
}
