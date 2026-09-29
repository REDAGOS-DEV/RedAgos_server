<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\Department;
use App\Enums\LineClosureReason;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Notifications\BloodRequestSubmitted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Recording a patient's need and splitting it across facilities.
 *
 * A Patient Transfusion Request holds what the patient needs, per component,
 * and never changes it. Each facility asked for a share gets a facility
 * allocation of its own. The hospital may ask for less than the patient needs
 * and ask again later; it may never ask for more.
 */
class TransfusionRequestTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    private Facility $thirdCentre;

    private User $thirdIssuance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        $this->thirdCentre = Facility::factory()->approved()->create(['name' => 'Digos Blood Center']);
        $this->thirdIssuance = User::factory()->bloodCenterStaff($this->thirdCentre, Department::Issuance)->create();
    }

    public function test_the_hospital_splits_a_requirement_across_three_facilities(): void
    {
        $response = $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 5]],
                [
                    [$this->centre, [[$this->prbc, 1]]],
                    [$this->otherCentre, [[$this->prbc, 3]]],
                    [$this->thirdCentre, [[$this->prbc, 1]]],
                ],
            ))
            ->assertCreated()
            ->assertJsonPath('request.reference_number', "PTR-{$this->hospital->id}-0001")
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.allocation_count', 3)
            ->assertJsonPath('request.totals.required', 5)
            ->assertJsonPath('request.totals.requested', 5)
            ->assertJsonPath('request.totals.awaiting', 5)
            ->assertJsonPath('request.totals.approved', 0)
            ->assertJsonPath('request.totals.remaining', 5)
            ->assertJsonPath('request.totals.unallocated', 0)
            ->assertJsonPath('request.needs_allocation', false)
            ->assertJsonPath('request.lines.0.line_status', 'awaiting_response');

        $allocations = collect($response->json('request.allocations'))->keyBy('target_facility.name');

        $this->assertSame(1, $allocations['Davao Blood Center']['quantity']);
        $this->assertSame(3, $allocations['Tagum Blood Center']['quantity']);
        $this->assertSame(1, $allocations['Digos Blood Center']['quantity']);
        $this->assertSame('Awaiting review', $allocations['Tagum Blood Center']['allocation_status_label']);
        $this->assertSame(
            ["RQ-{$this->hospital->id}-0001", "RQ-{$this->hospital->id}-0002", "RQ-{$this->hospital->id}-0003"],
            collect($response->json('request.allocations'))->pluck('reference_number')->all()
        );

        $requirement = TransfusionRequest::query()->sole();
        $this->assertNotNull($requirement->internal_stock_checked_at);
        $this->assertSame(3, BloodRequest::query()->where('transfusion_request_id', $requirement->id)->count());

        // Each centre hears about its own share, and only its own.
        foreach ([$this->issuance, $this->otherIssuance, $this->thirdIssuance] as $staff) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $staff->id,
                'type' => BloodRequestSubmitted::class,
            ]);
        }
    }

    public function test_a_centre_sees_its_share_against_what_the_patient_needs(): void
    {
        $requirement = $this->recordTransfusion(
            [[$this->prbc, 5]],
            [[$this->centre, [[$this->prbc, 1]]], [$this->otherCentre, [[$this->prbc, 3]]]],
        );
        $mine = $this->allocationAt($requirement, $this->centre);

        $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('request.quantity', 1)
            ->assertJsonPath('request.transfusion_request.reference_number', $requirement->reference_number)
            ->assertJsonPath('request.transfusion_request.required.0.quantity', 5)
            ->assertJsonPath('request.transfusion_request.required.0.component', 'Packed RBC')
            ->assertJsonMissingPath('request.transfusion_request.allocations');
    }

    public function test_asking_for_more_than_the_patient_needs_is_refused(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 5]],
                [[$this->centre, [[$this->prbc, 3]]], [$this->otherCentre, [[$this->prbc, 3]]]],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations']);

        $this->assertSame(0, TransfusionRequest::query()->count(), 'Nothing is written when the split is refused.');
        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_a_shortfall_is_allowed_and_left_unallocated(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 5], [$this->ffp, 2]],
                [[$this->centre, [[$this->prbc, 2]]]],
            ))
            ->assertCreated()
            ->assertJsonPath('request.totals.required', 7)
            ->assertJsonPath('request.totals.awaiting', 2)
            ->assertJsonPath('request.totals.unallocated', 5)
            ->assertJsonPath('request.needs_allocation', true)
            ->assertJsonPath('request.lines.1.line_status', 'needs_allocation')
            ->assertJsonPath('request.allocations.0.items.0.quantity', 2);
    }

    public function test_the_hospitals_own_stock_check_must_be_confirmed(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 2]],
                [[$this->centre, [[$this->prbc, 2]]]],
                ['internal_stock_confirmed' => false],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['internal_stock_confirmed']);
    }

    public function test_each_facility_is_asked_once_per_submission(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 4]],
                [[$this->centre, [[$this->prbc, 2]]], [$this->centre, [[$this->prbc, 2]]]],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations.1.facility_id']);
    }

    public function test_only_a_blood_centre_can_be_asked(): void
    {
        $otherBank = Facility::factory()->bloodBank()->approved()->create();

        foreach ([$this->hospital, $otherBank] as $facility) {
            $this->actingAs($this->requester)
                ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                    [[$this->prbc, 2]],
                    [[$facility, [[$this->prbc, 2]]]],
                ))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['allocations.0.facility_id']);
        }
    }

    public function test_a_component_the_patient_does_not_need_cannot_be_asked_for(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 2]],
                [[$this->centre, [[$this->ffp, 1]]]],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations.0.lines.0.component_id']);
    }

    public function test_at_least_one_unit_must_be_asked_of_someone(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 2]],
                [[$this->centre, [[$this->prbc, 0]]]],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations']);
    }

    public function test_the_hospital_lists_its_requirements_and_its_restock_orders_apart(): void
    {
        $this->scenarioTransfusion();

        BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->state(['request_purpose' => 'replenishment', 'patient_surname' => null, 'patient_first_name' => null])
            ->withComponents([[$this->prbc, 4]])
            ->create();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/transfusion-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.totals.required', 5)
            ->assertJsonPath('data.0.facilities.0', 'Davao Blood Center')
            ->assertJsonMissingPath('data.0.allocations');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/blood-requests?request_purpose=replenishment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_purpose', 'replenishment');
    }

    public function test_either_reference_on_the_paperwork_finds_the_requirement(): void
    {
        $requirement = $this->scenarioTransfusion();
        $allocation = $this->allocationAt($requirement, $this->centre);

        foreach ([$requirement->reference_number, $allocation->reference_number] as $reference) {
            $this->actingAs($this->requester)
                ->getJson("/api/hospital/transfusion-requests/track/{$reference}")
                ->assertOk()
                ->assertJsonPath('request.id', $requirement->id);
        }

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/transfusion-requests/track/PTR-0-9999')
            ->assertNotFound();
    }

    public function test_another_hospital_cannot_reach_the_requirement(): void
    {
        $requirement = $this->scenarioTransfusion();
        $outsider = User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->create();

        foreach (['', '/history', '/sourcing'] as $suffix) {
            $this->actingAs($outsider)
                ->getJson("/api/hospital/transfusion-requests/{$requirement->id}{$suffix}")
                ->assertNotFound();
        }

        $this->actingAs($outsider)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/cancel")
            ->assertNotFound();

        $this->actingAs($outsider)
            ->getJson("/api/hospital/transfusion-requests/track/{$requirement->reference_number}")
            ->assertNotFound();
    }

    public function test_a_blood_centre_account_cannot_record_a_requirement(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 2]],
                [[$this->centre, [[$this->prbc, 2]]]],
            ))
            ->assertForbidden();
    }

    public function test_withdrawing_an_unanswered_allocation_returns_its_units_to_unallocated(): void
    {
        $requirement = $this->recordTransfusion(
            [[$this->prbc, 4]],
            [[$this->centre, [[$this->prbc, 1]]], [$this->otherCentre, [[$this->prbc, 3]]]],
        );
        $theirs = $this->allocationAt($requirement, $this->otherCentre);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/allocations/{$theirs->id}/withdraw", [
                'reason' => 'Asked the wrong centre.',
            ])
            ->assertOk()
            ->assertJsonPath('request.totals.awaiting', 1)
            ->assertJsonPath('request.totals.unallocated', 3)
            ->assertJsonPath('request.needs_allocation', true);

        $this->assertSame(BloodRequestStatus::Cancelled, $theirs->fresh()->status);
        $this->assertDatabaseHas('blood_request_events', [
            'request_id' => $theirs->id,
            'transfusion_request_id' => $requirement->id,
            'event' => 'allocation_withdrawn',
        ]);
    }

    public function test_an_allocation_of_another_requirement_cannot_be_withdrawn_through_this_one(): void
    {
        $first = $this->scenarioTransfusion();
        $second = $this->scenarioTransfusion($this->otherCentre, ['patient_surname' => 'Reyes']);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$first->id}/allocations/{$this->allocationAt($second, $this->otherCentre)->id}/withdraw")
            ->assertNotFound();
    }

    public function test_an_approved_allocation_cannot_be_withdrawn(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 2]]]]);
        $mine = $this->allocationAt($requirement, $this->centre);
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)->postJson("/api/blood-center/blood-requests/{$mine->id}/allocate")->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/allocations/{$mine->id}/withdraw")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_not_withdrawable');
    }

    public function test_cancelling_withdraws_every_unanswered_allocation(): void
    {
        $requirement = $this->recordTransfusion(
            [[$this->prbc, 4]],
            [[$this->centre, [[$this->prbc, 1]]], [$this->otherCentre, [[$this->prbc, 3]]]],
        );
        $mine = $this->allocationAt($requirement, $this->centre);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$mine->id}/reject", ['reason' => 'None issuable.'])
            ->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/cancel", ['reason' => 'Patient transferred.'])
            ->assertOk()
            ->assertJsonPath('request.status', 'cancelled')
            ->assertJsonPath('request.is_open', false)
            ->assertJsonPath('request.cancellation_reason', 'Patient transferred.')
            ->assertJsonPath('request.totals.unallocated', 0);

        $this->assertSame(BloodRequestStatus::Rejected, $mine->fresh()->status, 'A refusal stays a refusal.');
        $this->assertSame(BloodRequestStatus::Cancelled, $this->allocationAt($requirement, $this->otherCentre)->status);
        $this->assertDatabaseHas('blood_request_events', [
            'transfusion_request_id' => $requirement->id,
            'request_id' => null,
            'event' => 'transfusion_cancelled',
        ]);
    }

    public function test_cancelling_is_refused_once_a_unit_is_held_for_the_patient(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 2]]]]);
        $this->stock($this->centre, $this->prbc, 1);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$this->allocationAt($requirement, $this->centre)->id}/allocate")
            ->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_has_supply');

        $this->assertSame(BloodRequestStatus::Processing, $requirement->fresh()->status);
    }

    public function test_closing_a_component_stops_asking_every_facility_for_it(): void
    {
        $requirement = $this->recordTransfusion(
            [[$this->prbc, 2], [$this->ffp, 2]],
            [
                [$this->centre, [[$this->prbc, 2], [$this->ffp, 1]]],
                [$this->otherCentre, [[$this->ffp, 1]]],
            ],
        );
        $mine = $this->allocationAt($requirement, $this->centre);
        $theirs = $this->allocationAt($requirement, $this->otherCentre);
        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)->postJson("/api/blood-center/blood-requests/{$mine->id}/allocate")->assertOk();

        $ffpLine = $requirement->items()->where('component_id', $this->ffp->id)->value('id');

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/items/{$ffpLine}/close", [
                'note' => 'Bleeding controlled.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'processing')
            ->assertJsonPath('request.lines.1.closure_note', 'Bleeding controlled.')
            ->assertJsonPath('request.lines.1.awaiting', 0)
            ->assertJsonPath('request.lines.1.unallocated', 0)
            ->assertJsonPath('request.needs_allocation', false);

        // The centre holding packed cells keeps them; its plasma is closed.
        $mineLine = $mine->items()->where('component_id', $this->ffp->id)->first();
        $this->assertNotNull($mineLine->closed_at);
        $this->assertSame(LineClosureReason::NotNeeded, $mineLine->closure_reason);
        $this->assertSame(BloodRequestStatus::Processing, $mine->fresh()->status);

        // The centre asked only for plasma has nothing left to answer.
        $this->assertSame(BloodRequestStatus::Cancelled, $theirs->fresh()->status);

        // Released, the packed cells finish the requirement short but closed.
        $this->actingAs($this->issuance)->postJson("/api/blood-center/blood-requests/{$mine->id}/release")->assertOk();

        $requirement->refresh();
        $this->assertSame(BloodRequestStatus::Partial, $requirement->status);
        $this->assertNotNull($requirement->closed_at);
        $this->assertTrue($requirement->isClosed());
    }

    public function test_closing_everything_before_anything_is_approved_is_refused(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 2]]]]);
        $line = $requirement->items()->value('id');

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/items/{$line}/close")
            ->assertStatus(409)
            ->assertJsonPath('code', 'close_would_empty');
    }

    public function test_an_allocations_line_is_closed_on_the_requirement_not_on_the_allocation(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 2]]]]);
        $mine = $this->allocationAt($requirement, $this->centre);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$mine->id}/items/{$this->lineFor($mine, $this->prbc)}/close")
            ->assertStatus(409)
            ->assertJsonPath('code', 'close_on_transfusion_request');
    }

    public function test_a_requirement_starts_its_own_timeline(): void
    {
        $requirement = $this->scenarioTransfusion();

        $events = $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$requirement->id}/history")
            ->assertOk()
            ->assertJsonPath('reference_number', $requirement->reference_number)
            ->json('events');

        $this->assertSame(['transfusion_created', 'submitted'], array_column($events, 'event'));
        $this->assertSame($this->requester->id, $events[0]['actor']['id']);
        $this->assertSame('Davao Blood Center', $events[1]['allocation']['facility']);
    }
}
