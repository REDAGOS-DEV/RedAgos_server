<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\BloodUnit;
use App\Models\RequestAllocation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Every request keeps an account of what happened to it.
 *
 * One event per change, each naming who did it, the status it moved between,
 * and every line's figures at that moment — so what was asked for and what was
 * provided can be read back as it stood at each step.
 */
class RequestHistoryTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_a_portal_request_records_each_step_from_submission_to_receipt(): void
    {
        $response = $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', [
                'internal_stock_confirmed' => true,
                'blood_type_id' => $this->bloodType->id,
                'urgency_level' => 'routine',
                'patient_surname' => 'Reyes',
                'patient_first_name' => 'Ana',
                'patient_age' => 40,
                'patient_sex' => 'female',
                'lines' => [
                    ['component_id' => $this->prbc->id, 'quantity' => 2, 'indication_code' => 'R-1'],
                    ['component_id' => $this->ffp->id, 'quantity' => 2, 'indication_code' => 'F-1'],
                ],
                'allocations' => [[
                    'facility_id' => $this->centre->id,
                    'lines' => [
                        ['component_id' => $this->prbc->id, 'quantity' => 2],
                        ['component_id' => $this->ffp->id, 'quantity' => 2],
                    ],
                ]],
            ])
            ->assertCreated();

        $requirementId = $response->json('request.id');
        $request = BloodRequest::query()->findOrFail($response->json('request.allocations.0.id'));
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk();

        $events = $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}/history")
            ->assertOk()
            ->json('events');

        $this->assertSame(
            ['submitted', 'allocated', 'released', 'receipt_confirmed'],
            array_column($events, 'event')
        );

        [$submitted, $allocated, $released, $received] = $events;

        $this->assertNull($submitted['from_status']);
        $this->assertSame('pending', $submitted['to_status']);
        $this->assertSame($this->requester->id, $submitted['actor']['id']);
        $this->assertSame($this->hospital->id, $submitted['actor_facility']['id']);

        $this->assertSame(['pending', 'processing'], [$allocated['from_status'], $allocated['to_status']]);
        $this->assertSame(3, $allocated['unit_count']);

        $this->assertSame(['processing', 'partial'], [$released['from_status'], $released['to_status']]);
        $this->assertSame($this->centre->id, $released['actor_facility']['id']);

        $ffp = collect($released['lines'])->firstWhere('component', 'Fresh Frozen Plasma');
        $this->assertSame(
            ['requested' => 2, 'fulfilled' => 1, 'remaining' => 1, 'status' => 'partial'],
            array_intersect_key($ffp, array_flip(['requested', 'fulfilled', 'remaining', 'status']))
        );
        $this->assertSame('Partially Fulfilled', $ffp['status_label']);

        $this->assertSame(3, collect($received['lines'])->sum('received'));

        // The requirement's own timeline: its creation, then every step of
        // the allocation, each naming the allocation it happened to.
        $timeline = $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$requirementId}/history")
            ->assertOk()
            ->json('events');

        $this->assertSame(
            ['transfusion_created', 'submitted', 'allocated', 'released', 'receipt_confirmed'],
            array_column($timeline, 'event')
        );
        $this->assertNull($timeline[0]['allocation']);
        $this->assertSame($request->reference_number, $timeline[1]['allocation']['reference_number']);
        $this->assertSame(2, collect($timeline[0]['lines'])->firstWhere('component', 'Fresh Frozen Plasma')['required']);
    }

    public function test_the_snapshot_keeps_the_figures_as_they_stood_at_the_time(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->stock($this->centre, $this->ffp, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $allocations = $request->events()->where('event', 'allocated')->get();

        $this->assertSame(0, collect($allocations[0]->lines)->firstWhere('component', 'Fresh Frozen Plasma')['reserved']);
        $this->assertSame(2, collect($allocations[1]->lines)->firstWhere('component', 'Fresh Frozen Plasma')['reserved']);
    }

    public function test_a_walk_in_records_who_confirmed_it(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $events = $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$response->json('request.id')}/history")
            ->assertOk()
            ->json('events');

        $this->assertCount(1, $events);
        $this->assertSame('walk_in_recorded', $events[0]['event']);
        $this->assertStringContainsString('Ana Lim', $events[0]['note']);
        $this->assertStringContainsString('Southern Hospital Blood Bank', $events[0]['note']);
        $this->assertSame($this->issuance->id, $events[0]['actor']['id']);
    }

    public function test_closing_a_line_records_the_line_and_the_reason(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->allocateAndRelease($request);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close", [
                'note' => 'Platelet donor pool exhausted.',
            ])
            ->assertOk();

        $event = $request->events()->where('event', 'line_closed')->firstOrFail();

        $this->assertSame($this->lineFor($request, $this->platelets), $event->request_item_id);
        $this->assertSame('unavailable', $event->meta['closure_reason']);
        $this->assertSame(1, $event->meta['closed_quantity']);
        $this->assertStringContainsString('Platelet donor pool exhausted.', $event->note);
    }

    public function test_a_rejection_records_its_reason(): void
    {
        $request = $this->scenarioRequest();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/reject", ['reason' => 'Invalid request form.'])
            ->assertOk();

        $event = $request->events()->where('event', 'rejected')->firstOrFail();
        $this->assertSame(BloodRequestStatus::Rejected, $event->to_status);
        $this->assertSame('Invalid request form.', $event->note);
    }

    public function test_the_history_is_scoped_to_the_two_facilities_on_the_request(): void
    {
        $request = $this->scenarioRequest();

        $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/history")
            ->assertOk();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}/history")
            ->assertOk();

        $this->actingAs($this->otherIssuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/history")
            ->assertNotFound();
    }

    public function test_an_event_cannot_be_rewritten(): void
    {
        $request = $this->scenarioRequest();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/reject", ['reason' => 'Invalid request form.'])
            ->assertOk();

        $event = BloodRequestEvent::query()->firstOrFail();

        $this->expectException(LogicException::class);

        $event->update(['note' => 'Something else.']);
    }

    public function test_an_expired_hold_is_recorded_and_the_request_reopened(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->assertSame(BloodRequestStatus::Processing, $request->fresh()->status);

        BloodUnit::query()->update(['expiry_date' => now()->subDays(2)->toDateString()]);

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $request = $request->fresh();
        $this->assertSame(BloodRequestStatus::Pending, $request->status);
        $this->assertNull($request->reviewed_by);
        $this->assertSame(0, RequestAllocation::query()->where('status', AllocationStatus::Allocated)->count());

        $event = $request->events()->where('event', 'hold_expired')->firstOrFail();
        $this->assertNull($event->actor_id, 'The scheduler did this, not a person.');
        $this->assertCount(2, $event->unit_ids);
    }
}
