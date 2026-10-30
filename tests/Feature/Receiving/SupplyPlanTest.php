<?php

namespace Tests\Feature\Receiving;

use App\Enums\BloodRequestStatus;
use App\Enums\HandoverMethod;
use App\Enums\RequestEventType;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\BloodRequestItem;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\SupplyPlan;
use App\Notifications\BloodRequestDecided;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\PlansSupply;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\Feature\Receiving\Concerns\SendsWeeklyRequests;
use Tests\TestCase;

/**
 * A replenishment order is planned before it is reserved (project owner, 2026-10-29).
 *
 * The centre says how many units of each line it can supply, whether the
 * hospital picks the order up or the centre delivers it, and when. Nothing is
 * reserved before that, never more of a line than was committed, and whatever
 * the commitments leave out is closed when the order is dispatched. The plan
 * may change until units go out, and the hospital is told each time.
 */
class SupplyPlanTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase, PlansSupply, SendsWeeklyRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelToMonday();
        $this->scheduleWith($this->centre);
    }

    public function test_the_centre_confirms_what_it_will_supply_and_when_it_is_picked_up(): void
    {
        Notification::fake();

        $request = $this->weeklyOrder([[$this->bloodType, $this->prbc, 4], [$this->bloodType, $this->ffp, 2]]);
        $prbc = $this->lineFor($request, $this->prbc);
        $ffp = $this->lineFor($request, $this->ffp);

        $this->sendSupplyPlan($request, $this->issuance, [$prbc => 3, $ffp => 0], [
            'handover_method' => 'pickup',
            'scheduled_at' => '2026-10-07T10:30:00+08:00',
            'handover_address' => 'Issuance counter, ground floor',
        ])
            ->assertOk()
            ->assertJsonPath('request.supply_plan.handover_method', 'pickup')
            ->assertJsonPath('request.supply_plan.handover_label', 'Pick-up')
            ->assertJsonPath('request.supply_plan.handover_address', 'Issuance counter, ground floor')
            ->assertJsonPath('request.supply_plan.contact_phone', '(082) 227-2731')
            ->assertJsonPath('request.committed_quantity', 3)
            // Planning reserves nothing, so the request is still waiting.
            ->assertJsonPath('request.status', BloodRequestStatus::Pending->value);

        $this->assertSame(3, BloodRequestItem::query()->findOrFail($prbc)->committed_quantity);
        $this->assertSame(0, BloodRequestItem::query()->findOrFail($ffp)->committed_quantity);

        $plan = SupplyPlan::query()->where('request_id', $request->id)->sole();
        $this->assertSame(HandoverMethod::Pickup, $plan->handover_method);
        $this->assertTrue($plan->scheduled_at->equalTo(CarbonImmutable::parse('2026-10-07 10:30', 'Asia/Manila')));
        $this->assertSame($this->issuance->id, $plan->planned_by);

        $event = BloodRequestEvent::query()->where('request_id', $request->id)->latest('id')->firstOrFail();
        $this->assertSame(RequestEventType::SupplyPlanned, $event->event);
        $this->assertSame('Pick-up on Wed 7 Oct 2026, 10:30 AM · 3 of 6 unit(s).', $event->note);

        $this->assertDatabaseHas('audit_logs', ['action' => 'supply_plan.saved', 'actor_id' => $this->issuance->id]);

        Notification::assertSentTo($this->requester, BloodRequestDecided::class, function (BloodRequestDecided $notice): bool {
            $data = $notice->toDatabase($this->requester);

            return $data['outcome'] === 'planned'
                && $data['title'] === 'Replenishment order confirmed'
                && str_contains($data['desc'], '3 of 6 unit(s), ready for pick-up on Wed 7 Oct, 10:30 AM');
        });
    }

    public function test_nothing_is_reserved_for_an_order_the_centre_has_not_confirmed(): void
    {
        $request = $this->weeklyOrder([[$this->bloodType, $this->prbc, 2]]);
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'supply_plan_required');

        $this->assertSame(0, $request->allocations()->count());
    }

    public function test_reserving_stops_at_what_was_committed(): void
    {
        $request = $this->weeklyOrder([[$this->bloodType, $this->prbc, 4]]);
        $line = $this->lineFor($request, $this->prbc);
        $this->planSupply($request, $this->issuance, [$line => 2]);
        $this->stock($this->centre, $this->prbc, 5);

        // The picker offers room for the commitment, not for the order.
        $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/units")
            ->assertOk()
            ->assertJsonPath('lines.0.allocatable', 2);

        $three = BloodUnit::query()->where('facility_id', $this->centre->id)->orderBy('id')->limit(3)->pluck('id')->all();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['unit_ids' => $three])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_ids.2' => 'committed to supply']);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 2)
            ->assertJsonPath('short_by', 0);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_fully_allocated');

        // Nothing more to find here, though two were asked beyond the commitment.
        $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.outstanding_quantity', 0)
            ->assertJsonPath('request.unclaimed_quantity', 2)
            ->assertJsonPath('request.items.0.committed_quantity', 2)
            ->assertJsonPath('inventory.lines.0.committed', 2)
            ->assertJsonPath('inventory.lines.0.unclaimed', 2);
    }

    public function test_the_dispatch_closes_what_lay_beyond_the_commitment(): void
    {
        $request = $this->weeklyOrder([[$this->bloodType, $this->prbc, 4], [$this->bloodType, $this->ffp, 2]]);
        $this->planSupply($request, $this->issuance, [
            $this->lineFor($request, $this->prbc) => 2,
            $this->lineFor($request, $this->ffp) => 0,
        ]);
        $this->stock($this->centre, $this->prbc, 4);
        $this->stock($this->centre, $this->ffp, 2);

        // The plasma is on the shelf, but none was committed.
        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value)
            ->assertJsonPath('closed_short.0.component', 'Packed RBC')
            ->assertJsonPath('closed_short.0.quantity', 2)
            ->assertJsonPath('closed_short.1.component', 'Fresh Frozen Plasma')
            ->assertJsonPath('closed_short.1.quantity', 2);

        $this->assertNotNull($request->fresh()->closed_at);
    }

    public function test_the_plan_may_change_until_units_go_out_but_not_below_what_is_reserved(): void
    {
        Notification::fake();

        $request = $this->weeklyOrder([[$this->bloodType, $this->prbc, 4]]);
        $line = $this->lineFor($request, $this->prbc);
        $this->planSupply($request, $this->issuance, [$line => 3]);
        $this->stock($this->centre, $this->prbc, 3);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->sendSupplyPlan($request, $this->issuance, [$line => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.committed_quantity' => 'already reserved']);

        $this->sendSupplyPlan($request, $this->issuance, [$line => 3], [
            'handover_method' => 'pickup',
            'handover_address' => null,
            'scheduled_at' => '2026-10-06T14:00:00+08:00',
        ])
            ->assertOk()
            ->assertJsonPath('request.supply_plan.handover_method', 'pickup')
            ->assertJsonPath('request.supply_plan.handover_address', null);

        $this->assertSame(
            RequestEventType::SupplyPlanChanged,
            BloodRequestEvent::query()->where('request_id', $request->id)->latest('id')->firstOrFail()->event
        );

        Notification::assertSentTo(
            $this->requester,
            BloodRequestDecided::class,
            fn (BloodRequestDecided $notice): bool => $notice->toDatabase($this->requester)['outcome'] === 'replanned'
        );

        // A weekly order is finished once it goes out.
        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->sendSupplyPlan($request, $this->issuance, null, [
            'lines' => [['request_item_id' => $line, 'committed_quantity' => 3]],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_closed');
    }

    public function test_a_replenishment_partly_dispatched_can_no_longer_be_replanned(): void
    {
        // Sent before weekly requests, so a dispatch closes nothing.
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->prbc, 3)
            ->replenishment()
            ->create();

        $this->planSupply($request, $this->issuance);
        $this->stock($this->centre, $this->prbc, 1);
        $this->allocateAndRelease($request);

        $this->sendSupplyPlan($request, $this->issuance)
            ->assertStatus(409)
            ->assertJsonPath('code', 'supply_plan_locked');
    }

    public function test_a_plan_is_refused_when_it_does_not_add_up(): void
    {
        $request = $this->weeklyOrder([[$this->bloodType, $this->prbc, 4], [$this->bloodType, $this->ffp, 2]]);
        $prbc = $this->lineFor($request, $this->prbc);
        $ffp = $this->lineFor($request, $this->ffp);

        // A delivery needs somewhere to go.
        $this->sendSupplyPlan($request, $this->issuance, null, ['handover_address' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['handover_address']);

        // Not on a day that has passed: today is Monday, 5 October.
        $this->sendSupplyPlan($request, $this->issuance, null, ['scheduled_at' => '2026-10-04T15:00:00+08:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);

        // Nor months away.
        $this->sendSupplyPlan($request, $this->issuance, null, ['scheduled_at' => '2027-01-05T09:00:00+08:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);

        // No more than was asked.
        $this->sendSupplyPlan($request, $this->issuance, [$prbc => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.committed_quantity']);

        // Nothing at all is a rejection.
        $this->sendSupplyPlan($request, $this->issuance, [$prbc => 0, $ffp => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines' => 'reject it instead']);

        // Every open line, once.
        $this->sendSupplyPlan($request, $this->issuance, null, [
            'lines' => [['request_item_id' => $prbc, 'committed_quantity' => 2]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines']);

        // Only this order's lines.
        $other = $this->scenarioRequest();
        $this->sendSupplyPlan($request, $this->issuance, null, [
            'lines' => [
                ['request_item_id' => $prbc, 'committed_quantity' => 2],
                ['request_item_id' => $ffp, 'committed_quantity' => 1],
                ['request_item_id' => $this->lineFor($other, $this->prbc), 'committed_quantity' => 1],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.2.request_item_id']);

        // A landline is fine; words are not.
        $this->sendSupplyPlan($request, $this->issuance, null, ['contact_phone' => 'call Ana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_phone']);

        $this->assertDatabaseCount('supply_plans', 0);
        $this->assertNull(BloodRequestItem::query()->findOrFail($prbc)->committed_quantity);
    }

    public function test_a_patients_request_is_approved_and_reserved_not_planned(): void
    {
        $this->sendSupplyPlan($this->scenarioRequest(), $this->issuance)
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_replenishment');
    }

    public function test_another_centre_cannot_plan_the_order(): void
    {
        $request = $this->weeklyOrder([[$this->bloodType, $this->prbc, 2]]);

        $this->sendSupplyPlan($request, $this->otherIssuance)->assertNotFound();
    }

    public function test_the_hospital_sees_what_is_coming_and_when(): void
    {
        $weekly = $this->sentWeekly([[$this->bloodType, $this->prbc, 4]]);
        $request = BloodRequest::query()->where('weekly_request_id', $weekly->id)->sole();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/weekly-requests/{$weekly->id}")
            ->assertOk()
            ->assertJsonPath('weekly_request.status', 'submitted')
            ->assertJsonPath('weekly_request.totals.committed', null)
            ->assertJsonPath('weekly_request.requests.0.supply_plan', null);

        $this->planSupply($request, $this->issuance, [$this->lineFor($request, $this->prbc) => 3]);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/weekly-requests/{$weekly->id}")
            ->assertOk()
            ->assertJsonPath('weekly_request.status', 'confirmed')
            ->assertJsonPath('weekly_request.status_label', 'Confirmed')
            ->assertJsonPath('weekly_request.totals.committed', 3)
            ->assertJsonPath('weekly_request.requests.0.supply_plan.handover_method', 'delivery')
            ->assertJsonPath('weekly_request.requests.0.supply_plan.contact_name', 'Ana Reyes')
            ->assertJsonPath('weekly_request.requests.0.items.0.committed_quantity', 3);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.supply_plan.notes', 'Bring a validated transport box.');
    }

    /**
     * Send a weekly request and return its one blood request, not yet planned.
     *
     * @param  array<int, array{0: BloodType, 1: BloodComponent, 2: int}>  $cells
     */
    private function weeklyOrder(array $cells): BloodRequest
    {
        $weekly = $this->sentWeekly($cells);

        return BloodRequest::query()->where('weekly_request_id', $weekly->id)->sole();
    }
}
