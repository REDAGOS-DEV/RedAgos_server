<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\Department;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * The workflow the Patient Transfusion Request was specified against.
 *
 * The patient needs five units of packed cells. The hospital asks Centre A
 * for one, B for three and C for one. A and B approve and reserve; C refuses.
 * The requirement then reads 5 required, 4 approved, 1 unallocated, and the
 * hospital asks D for the last unit. Every centre releases, the hospital
 * receives each allocation on its own, and the requirement is fulfilled.
 */
class TransfusionAllocationFlowTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    private Facility $centreC;

    private Facility $centreD;

    private User $issuanceC;

    private User $issuanceD;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        $this->centreC = Facility::factory()->approved()->create(['name' => 'Digos Blood Center']);
        $this->centreD = Facility::factory()->approved()->create(['name' => 'Mati Blood Center']);
        $this->issuanceC = User::factory()->bloodCenterStaff($this->centreC, Department::Issuance)->create();
        $this->issuanceD = User::factory()->bloodCenterStaff($this->centreD, Department::Issuance)->create();
    }

    public function test_a_refused_share_is_sourced_again_and_the_requirement_fulfilled(): void
    {
        $this->stock($this->centre, $this->prbc, 1);
        $this->stock($this->otherCentre, $this->prbc, 3);

        $requirement = $this->recordTransfusion([[$this->prbc, 5]], [
            [$this->centre, [[$this->prbc, 1]]],
            [$this->otherCentre, [[$this->prbc, 3]]],
            [$this->centreC, [[$this->prbc, 1]]],
        ]);

        $a = $this->allocationAt($requirement, $this->centre);
        $b = $this->allocationAt($requirement, $this->otherCentre);
        $c = $this->allocationAt($requirement, $this->centreC);

        // A approves: the requirement is being answered.
        $this->actingAs($this->issuance)->postJson("/api/blood-center/blood-requests/{$a->id}/allocate")->assertOk();
        $this->assertSame(BloodRequestStatus::Processing, $requirement->fresh()->status);

        $this->actingAs($this->otherIssuance)->postJson("/api/blood-center/blood-requests/{$b->id}/allocate")->assertOk();
        $this->actingAs($this->issuanceC)
            ->postJson("/api/blood-center/blood-requests/{$c->id}/reject", ['reason' => 'Reserved for scheduled surgery.'])
            ->assertOk();

        $this->show($requirement)
            ->assertJsonPath('request.status', 'processing')
            ->assertJsonPath('request.totals.required', 5)
            ->assertJsonPath('request.totals.approved', 4)
            ->assertJsonPath('request.totals.awaiting', 0)
            ->assertJsonPath('request.totals.remaining', 1)
            ->assertJsonPath('request.totals.unallocated', 1)
            ->assertJsonPath('request.needs_allocation', true)
            ->assertJsonPath('request.lines.0.line_status', 'partially_approved');

        // Search again: only the missing unit, and who has it.
        $this->stock($this->centreD, $this->prbc, 2);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$requirement->id}/sourcing")
            ->assertOk()
            ->assertJsonPath('lines.0.quantity', 1)
            ->assertJsonPath('lines.0.facilities.0.facility.name', 'Mati Blood Center')
            ->assertJsonPath('lines.0.facilities.0.suggested', 1)
            ->assertJsonPath('lines.0.shortfall', 0);

        // Asking for more than is missing is refused, even one unit more.
        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/allocations", [
                'allocations' => [['facility_id' => $this->centreD->id, 'lines' => [['component_id' => $this->prbc->id, 'quantity' => 2]]]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations']);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/allocations", [
                'allocations' => [['facility_id' => $this->centreD->id, 'lines' => [['component_id' => $this->prbc->id, 'quantity' => 1]]]],
            ])
            ->assertCreated()
            ->assertJsonPath('request.allocation_count', 4)
            ->assertJsonPath('request.totals.awaiting', 1)
            ->assertJsonPath('request.totals.unallocated', 0)
            ->assertJsonPath('request.needs_allocation', false);

        $d = $this->allocationAt($requirement, $this->centreD);
        $this->actingAs($this->issuanceD)->postJson("/api/blood-center/blood-requests/{$d->id}/allocate")->assertOk();

        $this->show($requirement)
            ->assertJsonPath('request.totals.approved', 5)
            ->assertJsonPath('request.lines.0.line_status', 'approved');

        // Released one centre at a time: partly fulfilled, then fulfilled.
        $this->actingAs($this->issuance)->postJson("/api/blood-center/blood-requests/{$a->id}/release")->assertOk();
        $this->assertSame(BloodRequestStatus::Partial, $requirement->fresh()->status);
        $this->assertNull($requirement->fresh()->closed_at);

        $this->actingAs($this->otherIssuance)->postJson("/api/blood-center/blood-requests/{$b->id}/release")->assertOk();
        $this->actingAs($this->issuanceD)->postJson("/api/blood-center/blood-requests/{$d->id}/release")->assertOk();

        $requirement->refresh();
        $this->assertSame(BloodRequestStatus::Fulfilled, $requirement->status);
        $this->assertNotNull($requirement->fulfilled_at);
        $this->assertNotNull($requirement->closed_at);

        // Receipt is per allocation, confirmed as each delivery arrives.
        foreach ([$a, $b, $d] as $allocation) {
            $this->actingAs($this->requester)
                ->postJson("/api/hospital/blood-requests/{$allocation->id}/confirm-receipt")
                ->assertOk();
        }

        $this->show($requirement)
            ->assertJsonPath('request.status', 'fulfilled')
            ->assertJsonPath('request.totals.fulfilled', 5)
            ->assertJsonPath('request.totals.received', 5)
            ->assertJsonPath('request.totals.remaining', 0);

        $events = $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$requirement->id}/history")
            ->json('events');

        $this->assertContains('rejected', array_column($events, 'event'));
        $this->assertContains('allocations_added', array_column($events, 'event'));
        $this->assertSame(3, collect($events)->where('event', 'receipt_confirmed')->count());
    }

    public function test_what_a_centre_closes_as_unavailable_goes_back_to_the_requirement(): void
    {
        $this->stock($this->otherCentre, $this->prbc, 2);

        $requirement = $this->recordTransfusion([[$this->prbc, 3]], [[$this->otherCentre, [[$this->prbc, 3]]]]);
        $b = $this->allocationAt($requirement, $this->otherCentre);

        $this->actingAs($this->otherIssuance)->postJson("/api/blood-center/blood-requests/{$b->id}/allocate")->assertOk();

        $this->show($requirement)
            ->assertJsonPath('request.totals.approved', 2)
            ->assertJsonPath('request.totals.awaiting', 1)
            ->assertJsonPath('request.needs_allocation', false);

        $this->actingAs($this->otherIssuance)
            ->postJson("/api/blood-center/blood-requests/{$b->id}/items/{$this->lineFor($b, $this->prbc)}/close", ['note' => 'No more O+.'])
            ->assertOk();

        $this->show($requirement)
            ->assertJsonPath('request.totals.awaiting', 0)
            ->assertJsonPath('request.totals.unallocated', 1)
            ->assertJsonPath('request.needs_allocation', true)
            ->assertJsonPath('request.allocations.0.allocation_status_label', 'Approved — units reserved');
    }

    public function test_a_centre_still_reviewing_its_share_is_not_asked_again(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 3]], [[$this->centre, [[$this->prbc, 1]]]]);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/allocations", [
                'allocations' => [['facility_id' => $this->centre->id, 'lines' => [['component_id' => $this->prbc->id, 'quantity' => 1]]]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations.0.facility_id']);
    }

    public function test_a_closed_requirement_cannot_be_allocated(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 1]]]]);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/cancel")
            ->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/allocations", [
                'allocations' => [['facility_id' => $this->otherCentre->id, 'lines' => [['component_id' => $this->prbc->id, 'quantity' => 1]]]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_closed');
    }

    public function test_the_last_unit_can_only_be_asked_for_once(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 1]]]]);
        $ask = fn (Facility $centre) => $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/allocations", [
                'allocations' => [['facility_id' => $centre->id, 'lines' => [['component_id' => $this->prbc->id, 'quantity' => 1]]]],
            ]);

        // Two members of staff answering the same banner: the requirement's
        // row lock serialises them, and the second sees nothing unallocated.
        $ask($this->otherCentre)->assertCreated();
        $ask($this->centreC)
            ->assertUnprocessable()
            ->assertJsonPath('errors.allocations.0', 'Every unit of Packed RBC is already approved or awaiting a facility\'s answer.');

        $this->assertSame(2, $requirement->facilityAllocations()->count());
    }

    public function test_an_expired_hold_puts_the_requirement_back_to_awaiting(): void
    {
        $this->stock($this->centre, $this->prbc, 2);
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 2]]]]);
        $a = $this->allocationAt($requirement, $this->centre);

        $this->actingAs($this->issuance)->postJson("/api/blood-center/blood-requests/{$a->id}/allocate")->assertOk();
        $this->assertSame(BloodRequestStatus::Processing, $requirement->fresh()->status);

        BloodUnit::query()->update(['expiry_date' => now()->subDays(2)->toDateString()]);
        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(BloodRequestStatus::Pending, $requirement->fresh()->status);
        $this->show($requirement)
            ->assertJsonPath('request.totals.approved', 0)
            ->assertJsonPath('request.totals.awaiting', 2);
    }

    public function test_the_hospitals_notification_opens_the_requirement(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 1]], [[$this->centre, [[$this->prbc, 1]]]]);
        $a = $this->allocationAt($requirement, $this->centre);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$a->id}/reject", ['reason' => 'None issuable.'])
            ->assertOk();

        $notification = $this->requester->notifications()->firstOrFail();

        $this->assertSame("/hospital/transfusion-requests/{$requirement->id}", $notification->data['action_route']);
        $this->assertStringContainsString('ask another facility', $notification->data['desc']);
    }

    private function show(TransfusionRequest $requirement): TestResponse
    {
        return $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$requirement->id}")
            ->assertOk();
    }
}
