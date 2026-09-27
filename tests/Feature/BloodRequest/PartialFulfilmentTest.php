<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\Department;
use App\Enums\LineClosureReason;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Supplying what is on the shelf, and keeping what was asked for intact.
 *
 * The scenario the workflow was specified against: PRBC 2, FFP 2, platelets 1,
 * against a shelf of 2, 1 and none. The centre supplies what it has instead of
 * rejecting the request, the requested quantities never change, each line
 * reports its own outcome, and the request stays Partially Fulfilled until the
 * rest is supplied, closed or sourced elsewhere.
 */
class PartialFulfilmentTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_the_available_components_are_supplied_and_the_request_is_partially_fulfilled(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 3)
            ->assertJsonPath('short_by', 2)
            ->assertJsonPath('status', BloodRequestStatus::Processing->value);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value);

        $response = $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.status', BloodRequestStatus::Partial->value)
            ->assertJsonPath('request.status_label', 'Partially Fulfilled')
            ->assertJsonPath('request.is_open', true)
            ->assertJsonPath('request.quantity', 5)
            ->assertJsonPath('request.fulfilled_quantity', 3)
            ->assertJsonPath('request.remaining_quantity', 2);

        $lines = collect($response->json('request.items'))->keyBy('component.name');

        $this->assertSame([2, 2, 0, 'fulfilled'], $this->figures($lines['Packed RBC']));
        $this->assertSame([2, 1, 1, 'partial'], $this->figures($lines['Fresh Frozen Plasma']));
        $this->assertSame([1, 0, 1, 'unfulfilled'], $this->figures($lines['Platelet Concentrate']));
    }

    public function test_the_requested_quantities_are_never_changed_by_fulfilment(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);

        $this->allocateAndRelease($request);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close", [
                'note' => 'No platelets on the shelf.',
            ])
            ->assertOk();

        $this->assertSame(
            [2, 2, 1],
            BloodRequestItem::query()->where('request_id', $request->id)->orderBy('id')->pluck('quantity')->all()
        );
    }

    public function test_a_partly_fulfilled_request_can_be_topped_up_to_fulfilled(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        $this->stock($this->centre, $this->ffp, 1);
        $this->stock($this->centre, $this->platelets, 1);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 5)
            ->assertJsonPath('short_by', 0)
            ->assertJsonPath('status', BloodRequestStatus::Partial->value);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Fulfilled->value);
    }

    public function test_the_centre_can_close_a_remainder_it_cannot_supply(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close", [
                'note' => 'No platelets on the shelf.',
            ])
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value)
            ->assertJsonPath('is_open', true);

        $line = BloodRequestItem::query()->find($this->lineFor($request, $this->platelets));
        $this->assertSame(LineClosureReason::Unavailable, $line->closure_reason);
        $this->assertSame($this->issuance->id, $line->closed_by);
        $this->assertSame('No platelets on the shelf.', $line->closure_note);
    }

    public function test_closing_every_remainder_ends_the_request_as_partially_fulfilled_and_closed(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        foreach ([$this->ffp, $this->platelets] as $component) {
            $this->actingAs($this->issuance)
                ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $component)}/close")
                ->assertOk();
        }

        $request = $request->fresh();
        $this->assertSame(BloodRequestStatus::Partial, $request->status);
        $this->assertNotNull($request->closed_at, 'Every line is resolved, so the request is finished.');
        $this->assertNull($request->fulfilled_at, 'It was never fully supplied.');

        $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertJsonPath('request.is_open', false);
    }

    public function test_a_closed_request_cannot_be_allocated_again(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        foreach ([$this->ffp, $this->platelets] as $component) {
            $this->actingAs($this->issuance)
                ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $component)}/close")
                ->assertOk();
        }

        $this->stock($this->centre, $this->ffp, 1);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_closed');
    }

    public function test_a_closed_line_is_never_allocated_even_when_stock_arrives(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close")
            ->assertOk();

        $this->stock($this->centre, $this->platelets, 1);
        $this->stock($this->centre, $this->ffp, 1);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 4);

        $this->assertSame(
            0,
            $request->allocations()->where('request_item_id', $this->lineFor($request, $this->platelets))->count()
        );
    }

    public function test_closing_every_line_before_anything_was_supplied_is_refused(): void
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->platelets, 1)
            ->create();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close")
            ->assertStatus(409)
            ->assertJsonPath('code', 'close_would_empty');

        $this->assertNull($request->items()->first()->closed_at);
    }

    public function test_a_line_with_nothing_left_to_supply_cannot_be_closed(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->prbc)}/close")
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_close');
    }

    public function test_the_hospital_can_close_a_remainder_it_no_longer_needs(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close", [
                'note' => 'Patient stabilised.',
            ])
            ->assertOk();

        $this->assertSame(
            LineClosureReason::NotNeeded,
            BloodRequestItem::query()->find($this->lineFor($request, $this->platelets))->closure_reason
        );
    }

    public function test_another_facility_cannot_close_a_line(): void
    {
        $request = $this->scenarioRequest();

        $this->actingAs($this->otherIssuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close")
            ->assertNotFound();
    }

    public function test_closing_a_line_needs_the_approve_ability(): void
    {
        $request = $this->scenarioRequest();
        $collection = User::factory()
            ->bloodCenterStaff($this->centre, Department::Collection)
            ->create();

        $this->actingAs($collection)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close")
            ->assertForbidden();
    }

    public function test_the_review_reports_per_line_fulfilment_beside_live_stock(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);
        $this->stock($this->centre, $this->ffp, 3);

        $lines = collect(
            $this->actingAs($this->issuance)
                ->getJson("/api/blood-center/blood-requests/{$request->id}")
                ->assertOk()
                ->json('inventory.lines')
        )->keyBy('component.name');

        $this->assertSame(1, $lines['Fresh Frozen Plasma']['fulfilled']);
        $this->assertSame(1, $lines['Fresh Frozen Plasma']['outstanding']);
        $this->assertSame(3, $lines['Fresh Frozen Plasma']['available']);
        $this->assertTrue($lines['Fresh Frozen Plasma']['can_fully_cover']);
        $this->assertSame('partial', $lines['Fresh Frozen Plasma']['line_status']);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function figures(array $line): array
    {
        return [
            $line['quantity'],
            $line['fulfilled_quantity'],
            $line['remaining_quantity'],
            $line['line_status'],
        ];
    }
}
