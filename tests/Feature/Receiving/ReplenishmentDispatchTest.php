<?php

namespace Tests\Feature\Receiving;

use App\Enums\AllocationStatus;
use App\Enums\BillingStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\RequestEventType;
use App\Enums\StaffRole;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\BloodUnit;
use App\Models\FacilityBloodComponent;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Notifications\BloodRequestDecided;
use App\Service\FulfillmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\PlansSupply;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\Feature\Receiving\Concerns\SendsWeeklyRequests;
use Tests\TestCase;

/**
 * A replenishment order holds no stock (project owner, 2026-11-07).
 *
 * Only a patient's request is tagged. A restock is confirmed, its bags stay on
 * the shelf for anyone, and they are picked and recorded at the moment they leave.
 */
class ReplenishmentDispatchTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase, PlansSupply, SendsWeeklyRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelToMonday();
        $this->scheduleWith($this->centre);
    }

    public function test_confirming_an_order_holds_nothing_back(): void
    {
        $request = $this->confirmedOrder(2);
        $this->stock($this->centre, $this->prbc, 2);

        $this->assertSame(0, $request->allocations()->count());
        $this->assertSame(0, Billing::query()->where('request_id', $request->id)->count());
        $this->assertSame(BloodRequestStatus::Pending, $request->fresh()->status);

        // A patient may still take the same bags while the order waits.
        $patient = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->prbc, 2)
            ->create();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$patient->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 2);
    }

    public function test_an_order_is_never_tagged(): void
    {
        $request = $this->confirmedOrder(2);
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'replenishment_not_tagged');

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'replenishment_not_tagged');

        $this->assertSame(0, $request->allocations()->count());
    }

    public function test_a_patients_request_is_never_dispatched_this_way(): void
    {
        $this->stock($this->centre, $this->prbc, 2);

        $patient = $this->scenarioRequest();
        $unit = BloodUnit::query()->where('component_id', $this->prbc->id)->value('id');

        $this->sendDispatch($patient, $this->issuance, [$unit])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_replenishment');

        $this->assertSame(BloodUnitStatus::Available, BloodUnit::query()->findOrFail($unit)->status);
    }

    public function test_the_units_are_recorded_as_they_go(): void
    {
        Notification::fake();

        FacilityBloodComponent::updateOrCreate(
            ['facility_id' => $this->centre->id, 'component_id' => $this->prbc->id],
            ['price' => 900]
        );

        $request = $this->confirmedOrder(2);
        $this->stock($this->centre, $this->prbc, 3);

        $picked = array_slice($this->unitsInReach($request, $this->issuance), 0, 2);

        $this->sendDispatch($request, $this->issuance, $picked, ['handed_to' => 'Pedro Cruz'])
            ->assertOk()
            ->assertJsonPath('released_units', $picked)
            ->assertJsonPath('status', BloodRequestStatus::Fulfilled->value)
            ->assertJsonPath('closed_short', []);

        foreach ($picked as $unitId) {
            $this->assertSame(BloodUnitStatus::Issued, BloodUnit::query()->findOrFail($unitId)->status);

            $allocation = RequestAllocation::query()->where('unit_id', $unitId)->sole();
            $this->assertSame(AllocationStatus::Released, $allocation->status);
            $this->assertSame($this->issuance->id, $allocation->released_by);
            $this->assertSame('Pedro Cruz', $allocation->handed_to);
            $this->assertNull($allocation->received_at);
        }

        // Every issuance has a bill, raised as the units go.
        $billing = Billing::query()->where('request_id', $request->id)->sole();
        $this->assertSame(BillingStatus::StatementOnly, $billing->status);
        $this->assertSame('1800.00', $billing->total_amount);

        $events = BloodRequestEvent::query()->where('request_id', $request->id)->pluck('event');
        $this->assertTrue($events->contains(RequestEventType::Released));
        $this->assertFalse($events->contains(RequestEventType::Allocated), 'An order is never tagged.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'request.released', 'actor_id' => $this->issuance->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'allocation.reserved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'request.allocated']);

        Notification::assertSentTo(
            $this->requester,
            BloodRequestDecided::class,
            fn (BloodRequestDecided $notice): bool => $notice->toDatabase($this->requester)['outcome'] === 'released'
        );

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('stocked_count', 2);
    }

    public function test_a_bag_tagged_for_a_patient_meanwhile_is_refused_by_name(): void
    {
        $request = $this->confirmedOrder(2);
        $this->stock($this->centre, $this->prbc, 2);

        [$free, $taken] = $this->unitsInReach($request, $this->issuance);

        $patient = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->prbc, 1)
            ->create();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$patient->id}/allocate", ['unit_ids' => [$taken]])
            ->assertOk();

        $this->sendDispatch($request, $this->issuance, [$free, $taken])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_ids.1' => 'no longer available']);

        $this->assertSame(BloodUnitStatus::Available, BloodUnit::query()->findOrFail($free)->status, 'Refused whole.');
        $this->assertSame(0, $request->allocations()->count());
    }

    public function test_a_unit_of_another_component_is_refused(): void
    {
        $request = $this->confirmedOrder(1);
        $this->stock($this->centre, $this->ffp, 1);

        $this->sendDispatch($request, $this->issuance, [BloodUnit::query()->value('id')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_ids.0' => 'blood type and component']);
    }

    public function test_the_units_must_be_named(): void
    {
        $request = $this->confirmedOrder(1);

        $this->sendDispatch($request, $this->issuance, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_ids');
    }

    public function test_whoever_dispatches_lists_and_picks_the_units(): void
    {
        $coordinator = User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create();

        $request = $this->confirmedOrder(1);
        $this->stock($this->centre, $this->prbc, 1);

        // Tagging stays the Inventory Control Officer's.
        $this->actingAs($coordinator)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/units")
            ->assertForbidden();

        $this->sendDispatch($request, $coordinator)->assertOk();

        $this->assertSame($coordinator->id, $request->allocations()->sole()->released_by);
    }

    public function test_another_centre_cannot_dispatch_the_order(): void
    {
        $request = $this->confirmedOrder(1);
        $this->stock($this->centre, $this->prbc, 1);

        $this->sendDispatch($request, $this->otherIssuance, ['RA1-anything'])->assertNotFound();
    }

    public function test_a_confirmed_order_waits_on_for_dispatch_until_it_goes(): void
    {
        $unconfirmed = $this->order(1);
        $confirmed = $this->confirmedOrder(1);
        $this->stock($this->centre, $this->prbc, 1);

        $listed = fn (string $query): array => array_column(
            $this->actingAs($this->issuance)->getJson("/api/blood-center/blood-requests?{$query}")->assertOk()->json('data'),
            'id'
        );

        $this->assertSame([$confirmed->id], $listed('awaiting_release=1&release_cleared=1'), 'For Dispatch has it.');
        $this->assertNotContains($unconfirmed->id, $listed('awaiting_release=1'));
        $this->assertSame([], $listed('awaiting_release=1&release_cleared=0'), 'Request Fulfillment never does: nothing is owed on it here.');

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/blood-requests/summary')
            ->assertOk()
            ->assertJsonPath('awaiting_release', 1)
            ->assertJsonPath('by_purpose.replenishment.awaiting_release', 1);

        $this->sendDispatch($confirmed, $this->issuance)->assertOk();

        $this->assertSame([], $listed('awaiting_release=1&release_cleared=1'));
        $this->assertSame([$confirmed->id], $listed('awaiting_receipt=1'));
    }

    public function test_a_restock_may_follow_with_more_of_what_was_committed(): void
    {
        // Not a weekly order, so its first delivery need not be its last.
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->prbc, 5)
            ->replenishment()
            ->create();

        $this->planSupply($request, $this->issuance, [$this->lineFor($request, $this->prbc) => 3]);

        $this->stock($this->centre, $this->prbc, 2);
        $this->sendDispatch($request, $this->issuance)
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value)
            ->assertJsonPath('closed_short', []);

        $this->assertFalse($request->fresh()->isClosed());

        // The last of the commitment closes what lay beyond it, so nothing lingers.
        $this->stock($this->centre, $this->prbc, 1);
        $this->sendDispatch($request, $this->issuance)
            ->assertOk()
            ->assertJsonPath('closed_short.0.quantity', 2);

        $request->refresh();
        $this->assertTrue($request->isClosed());
        $this->assertSame(BloodRequestStatus::Partial, $request->status);
        $this->assertSame(FulfillmentService::UNCOMMITTED_NOTE, $request->items()->sole()->closure_note);
    }

    public function test_a_late_write_up_can_be_corrected_to_when_the_delivery_really_left(): void
    {
        $head = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();

        $request = $this->confirmedOrder(1);
        $this->stock($this->centre, $this->prbc, 1);
        $this->travel(4)->hours();
        $this->sendDispatch($request, $head)->assertOk();

        $allocation = $request->allocations()->sole();

        $correct = fn ($at) => $this->actingAs($head)->postJson("/api/blood-center/allocations/{$allocation->id}/corrections", [
            'reason' => 'Written up after the courier had left.',
            'changes' => ['released_at' => $at->toIso8601String()],
        ]);

        // Before the moment it was recorded, which is all a stamp made at dispatch says.
        $correct(now()->subHours(2))->assertCreated();

        $this->assertTrue($allocation->fresh()->released_at->lt($allocation->allocated_at));

        // But never before the order was sent.
        $correct($request->fresh()->request_date->subDay())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['changes.released_at' => 'before the order was sent']);
    }

    public function test_units_held_for_an_order_before_this_change_go_back_on_the_shelf(): void
    {
        $order = $this->confirmedOrder(2);
        $patient = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->prbc, 1)
            ->create();

        $this->stock($this->centre, $this->prbc, 3);
        [$first, $second, $patients] = BloodUnit::query()->orderBy('id')->pluck('id')->all();

        foreach ([[$order, $first], [$order, $second], [$patient, $patients]] as [$request, $unitId]) {
            RequestAllocation::query()->create([
                'request_id' => $request->id,
                'request_item_id' => $request->items()->value('id'),
                'unit_id' => $unitId,
                'allocated_at' => now(),
                'allocated_by' => $this->issuance->id,
                'status' => AllocationStatus::Allocated,
            ]);

            BloodUnit::query()->whereKey($unitId)->update(['status' => BloodUnitStatus::Reserved->value]);
            DB::table('blood_requests')->where('id', $request->id)->update(['status' => BloodRequestStatus::Processing->value]);
        }

        (require database_path('migrations/2026_11_07_000002_return_replenishment_holds_to_stock.php'))->up();

        $this->assertSame(BloodUnitStatus::Available, BloodUnit::query()->findOrFail($first)->status);
        $this->assertSame(BloodUnitStatus::Available, BloodUnit::query()->findOrFail($second)->status);
        $this->assertSame(BloodRequestStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, $order->allocations()->where('status', AllocationStatus::Allocated)->count());

        // A patient's tag is exactly what it was.
        $this->assertSame(BloodUnitStatus::Reserved, BloodUnit::query()->findOrFail($patients)->status);
        $this->assertSame(BloodRequestStatus::Processing, $patient->fresh()->status);
        $this->assertSame(1, $patient->allocations()->where('status', AllocationStatus::Allocated)->count());

        // And the order goes out the new way.
        $this->sendDispatch($order, $this->issuance, [$first, $second])->assertOk();
    }

    private function order(int $quantity): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->prbc, $quantity)
            ->replenishment()
            ->create();
    }

    private function confirmedOrder(int $quantity): BloodRequest
    {
        return $this->planSupply($this->order($quantity), $this->issuance);
    }
}
