<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\RequestPurpose;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\ReplenishmentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\Feature\Receiving\Concerns\SendsWeeklyRequests;
use Tests\TestCase;

/**
 * Raising a replenishment request, and what stops one being raised wrongly.
 *
 * A replenishment is only ever sent as the weekly request (WeeklyRequestTest
 * covers the request days). What is held here is what any request to a centre
 * must satisfy: the requesting facility is never taken from input, a request
 * cannot be addressed to itself or to a facility that has no way to fulfil it,
 * submitting reserves nothing — approval does that, later — and a patient's
 * need is a Patient Transfusion Request, not a restock (TransfusionRequestTest).
 */
class SubmitBloodRequestTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase, SendsWeeklyRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelToMonday();
        $this->scheduleWith($this->centre);
    }

    public function test_it_records_a_pending_replenishment_addressed_to_the_chosen_centre(): void
    {
        $this->sendWeekly([[$this->bloodType, $this->prbc, 5]])
            ->assertCreated()
            ->assertJsonPath('weekly_request.requests.0.status', BloodRequestStatus::Pending->value)
            ->assertJsonPath('weekly_request.requests.0.quantity', 5)
            ->assertJsonPath('weekly_request.requests.0.target_facility.name', 'Davao Blood Center')
            ->assertJsonPath('weekly_request.requests.0.requesting_facility.id', $this->hospital->id)
            ->assertJsonPath('weekly_request.requests.0.outstanding_quantity', 5)
            ->assertJsonPath('weekly_request.requests.0.allocated_count', 0);

        $this->assertDatabaseHas('blood_requests', [
            'facility_id' => $this->hospital->id,
            'target_facility_id' => $this->centre->id,
            'requested_by' => $this->requester->id,
            'status' => BloodRequestStatus::Pending->value,
            'urgency_level' => UrgencyLevel::Routine->value,
            'request_purpose' => RequestPurpose::Replenishment->value,
            'patient_surname' => null,
            'transfusion_request_id' => null,
        ]);

        $this->assertDatabaseHas('blood_request_items', [
            'component_id' => $this->prbc->id,
            'quantity' => 5,
            'indication_code' => null,
        ]);
    }

    public function test_the_standalone_replenishment_endpoint_is_gone(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', [
                'target_facility_id' => $this->centre->id,
                'blood_type_id' => $this->bloodType->id,
                'urgency_level' => 'emergency',
                'request_purpose' => 'replenishment',
                'items' => [['component_id' => $this->prbc->id, 'quantity' => 2]],
            ])
            ->assertStatus(405);

        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_it_issues_reference_numbers_per_facility(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $otherRequester = User::factory()->bloodBankStaff($otherHospital)->create();
        ReplenishmentSchedule::factory()->between($otherHospital, $this->centre)->create();

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2], [$this->bloodType, $this->ffp, 1]])->assertCreated();

        $theirs = $this->actingAs($otherRequester)
            ->postJson('/api/hospital/weekly-requests', $this->weeklyPayload([[$this->bloodType, $this->prbc, 1]]))
            ->assertCreated()
            ->json('weekly_request.requests.0.reference_number');

        $this->assertSame(
            ["RQ-{$this->hospital->id}-0001"],
            BloodRequest::query()->where('facility_id', $this->hospital->id)->pluck('reference_number')->all()
        );
        $this->assertSame("RQ-{$otherHospital->id}-0001", $theirs, 'Each facility numbers its own requests from one.');
    }

    public function test_the_sequence_is_not_derived_lexicographically(): void
    {
        BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->create(['reference_number' => "RQ-{$this->hospital->id}-0100"]);

        $this->assertSame(
            "RQ-{$this->hospital->id}-0101",
            $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])->assertCreated()->json('weekly_request.requests.0.reference_number'),
            'As a string, 0100 sorts below 0099, so MAX() would reissue a taken number.'
        );
    }

    public function test_the_requesting_facility_is_taken_from_the_account_not_the_payload(): void
    {
        $someoneElse = Facility::factory()->bloodBank()->approved()->create();

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]], null, ['facility_id' => $someoneElse->id])
            ->assertCreated()
            ->assertJsonPath('weekly_request.requests.0.requesting_facility.id', $this->hospital->id);

        $this->assertDatabaseMissing('blood_requests', ['facility_id' => $someoneElse->id]);
    }

    public function test_a_request_cannot_be_addressed_to_an_ineligible_facility(): void
    {
        $otherBank = Facility::factory()->bloodBank()->approved()->create();
        $pending = Facility::factory()->pendingApproval()->create();

        foreach ([$this->hospital, $otherBank, $pending] as $facility) {
            $this->sendWeekly([[$this->bloodType, $this->prbc, 2]], $facility)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['target_facility_id']);
        }

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]], null, ['target_facility_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_facility_id']);

        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_submitting_holds_no_stock(): void
    {
        $this->stock($this->centre, $this->prbc, 6);

        $this->sendWeekly([[$this->bloodType, $this->prbc, 4]])->assertCreated();

        $this->assertDatabaseCount('request_allocations', 0);
        $this->assertSame(
            6,
            BloodUnit::query()->where('status', BloodUnitStatus::Available)->count(),
            'A submitted request is a question, not a claim. Approval reserves stock, not submission.'
        );
    }

    public function test_submission_writes_an_audit_row(): void
    {
        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'request.submitted',
            'actor_id' => $this->requester->id,
            'auditable_type' => BloodRequest::class,
        ]);
    }

    public function test_a_blood_centre_account_cannot_raise_a_request(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff()->create())
            ->postJson('/api/hospital/weekly-requests', $this->weeklyPayload([[$this->bloodType, $this->prbc, 2]]))
            ->assertForbidden();
    }

    public function test_an_unauthenticated_caller_cannot_raise_a_request(): void
    {
        $this->postJson('/api/hospital/weekly-requests', $this->weeklyPayload([[$this->bloodType, $this->prbc, 2]]))
            ->assertUnauthorized();
    }

    public function test_staff_of_an_unapproved_blood_bank_cannot_raise_a_request(): void
    {
        $pendingBank = Facility::factory()->bloodBank()->pendingApproval()->create();
        $staff = User::factory()->bloodBankStaff($pendingBank)->create();

        $this->actingAs($staff)
            ->postJson('/api/hospital/weekly-requests', $this->weeklyPayload([[$this->bloodType, $this->prbc, 2]]))
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_approved');
    }
}
