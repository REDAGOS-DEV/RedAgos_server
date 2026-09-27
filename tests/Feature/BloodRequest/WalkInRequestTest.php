<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\Department;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodRequest;
use App\Models\BloodRequestWalkIn;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\BloodRequestDecided;
use App\Notifications\BloodRequestSubmitted;
use App\Notifications\WalkInRequestRecorded;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * A watcher who brought a patient's request to the blood centre instead.
 *
 * Issuance phones the hospital blood bank; only if the hospital confirms is a
 * request recorded, and then it is the hospital's request — in its sequence,
 * in its list — not the watcher's and not the centre's. The watcher is kept as
 * the representative who presented it, the verifier as who vouched for it.
 */
class WalkInRequestTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_issuance_records_a_confirmed_walk_in_as_the_hospitals_pending_request(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated()
            ->assertJsonPath('request.status', BloodRequestStatus::Pending->value)
            ->assertJsonPath('request.request_source', RequestSource::BloodCenterWalkIn->value)
            ->assertJsonPath('request.is_walk_in', true)
            ->assertJsonPath('request.request_purpose', RequestPurpose::PatientTransfusion->value)
            ->assertJsonPath('request.requesting_facility.id', $this->hospital->id)
            ->assertJsonPath('request.target_facility.id', $this->centre->id)
            ->assertJsonPath('request.quantity', 5)
            ->assertJsonPath('request.patient.full_name', 'DELA CRUZ, Juan Santos')
            ->assertJsonPath('request.walk_in.representative.name', 'Pedro Dela Cruz')
            ->assertJsonPath('request.walk_in.representative.relationship', 'Son')
            ->assertJsonPath('request.walk_in.verification.verifier_name', 'Ana Lim')
            ->assertJsonPath('request.walk_in.verification.method', 'phone')
            ->assertJsonPath('request.walk_in.attending_physician', 'Dr. Maria Reyes');

        $request = BloodRequest::query()->findOrFail($response->json('request.id'));

        $this->assertNull($request->requested_by, 'Nobody at the hospital submitted it.');
        $this->assertSame($this->issuance->id, $request->recorded_by);
        $this->assertSame($this->issuance->id, $request->walkIn->verification_recorded_by);
        $this->assertSame(3, $request->items()->count());
    }

    public function test_a_walk_in_takes_the_next_number_in_the_hospitals_own_sequence(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', [
                'target_facility_id' => $this->centre->id,
                'blood_type_id' => $this->bloodType->id,
                'urgency_level' => 'routine',
                'request_purpose' => 'replenishment',
                'items' => [['component_id' => $this->prbc->id, 'quantity' => 4, 'indication_code' => 'R-1']],
            ])
            ->assertCreated()
            ->assertJsonPath('request.reference_number', "RQ-{$this->hospital->id}-0001");

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated()
            ->assertJsonPath('request.reference_number', "RQ-{$this->hospital->id}-0002");
    }

    public function test_the_walk_in_appears_in_the_hospitals_own_list(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/blood-requests?request_source=blood_center_walk_in')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_label', 'Blood Center Walk-in')
            ->assertJsonPath('data.0.recorder_name', trim($this->issuance->first_name.' '.$this->issuance->last_name));
    }

    public function test_the_centre_queue_can_be_filtered_by_source(): void
    {
        $this->scenarioRequest();

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'patient_surname' => 'Santos',
                'patient_first_name' => 'Maria',
            ]))
            ->assertCreated();

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/blood-requests?request_source=blood_center_walk_in')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/blood-requests?request_source=blood_bank_portal')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/blood-requests/summary')
            ->assertOk()
            ->assertJsonPath('open_by_source.blood_center_walk_in', 1)
            ->assertJsonPath('open_by_source.blood_bank_portal', 1);
    }

    public function test_nothing_is_recorded_unless_the_hospital_confirmed(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'verification' => ['confirmed' => false],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification.confirmed');

        $this->assertDatabaseCount('blood_requests', 0);
        $this->assertDatabaseCount('blood_request_walk_ins', 0);
    }

    public function test_the_person_who_confirmed_it_must_be_named(): void
    {
        $payload = $this->walkInPayload();
        unset($payload['verification']['verifier_name'], $payload['verification']['verifier_position'], $payload['verification']['verified_at']);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'verification.verifier_name',
                'verification.verifier_position',
                'verification.verified_at',
            ]);

        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_a_confirmation_dated_in_the_future_is_refused(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'verification' => ['verified_at' => now()->addHour()->toIso8601String()],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification.verified_at');
    }

    public function test_the_watcher_must_be_recorded(): void
    {
        $payload = $this->walkInPayload();
        unset($payload['representative']);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['representative.name', 'representative.relationship', 'representative.contact']);
    }

    public function test_the_patient_must_be_named(): void
    {
        $payload = $this->walkInPayload();
        unset($payload['patient_surname'], $payload['patient_first_name'], $payload['patient_age'], $payload['patient_sex']);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_surname', 'patient_first_name', 'patient_age', 'patient_sex']);
    }

    public function test_every_line_still_needs_its_indication(): void
    {
        $payload = $this->walkInPayload();
        $payload['items'] = [['component_id' => $this->prbc->id, 'quantity' => 2]];

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.indication_code');
    }

    public function test_the_purpose_is_always_patient_transfusion_whatever_is_sent(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'request_purpose' => 'replenishment',
            ]))
            ->assertCreated();

        $this->assertSame(
            RequestPurpose::PatientTransfusion,
            BloodRequest::query()->find($response->json('request.id'))->request_purpose
        );
    }

    public function test_the_target_is_always_the_callers_own_centre_whatever_is_sent(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'target_facility_id' => $this->otherCentre->id,
            ]))
            ->assertCreated();

        $this->assertSame(
            $this->centre->id,
            BloodRequest::query()->find($response->json('request.id'))->target_facility_id
        );
    }

    public function test_the_hospital_must_be_a_registered_blood_bank(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => $this->otherCentre->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hospital_id');

        $pending = Facility::factory()->bloodBank()->pendingApproval()->create();

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => $pending->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hospital_id');

        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_only_issuance_may_record_a_walk_in(): void
    {
        foreach ([StaffRole::InventoryControlOfficer, StaffRole::DispatchCoordinator] as $role) {
            $staff = User::factory()->bloodCenterStaff($this->centre, $role)->create();

            $this->actingAs($staff)
                ->getJson('/api/blood-center/blood-requests/walk-in/reference')
                ->assertOk();
        }

        foreach ([StaffRole::ItDataClerk, StaffRole::BillingClerk, StaffRole::Phlebotomist] as $role) {
            $staff = User::factory()->bloodCenterStaff($this->centre, $role)->create();

            $this->actingAs($staff)
                ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
                ->assertForbidden();
        }
    }

    public function test_a_hospital_account_cannot_reach_the_walk_in_routes(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertForbidden();
    }

    public function test_the_reference_lists_hospital_blood_banks_and_one_purpose(): void
    {
        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/blood-requests/walk-in/reference')
            ->assertOk()
            ->assertJsonCount(1, 'hospitals')
            ->assertJsonPath('hospitals.0.id', $this->hospital->id)
            ->assertJsonPath('purpose.value', RequestPurpose::PatientTransfusion->value)
            ->assertJsonStructure(['blood_types', 'components', 'priorities', 'id_types']);
    }

    public function test_the_hospital_is_told_a_request_was_recorded_on_its_behalf(): void
    {
        $colleague = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        foreach ([$this->requester, $colleague] as $account) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $account->id,
                'type' => WalkInRequestRecorded::class,
            ]);
        }

        $this->assertDatabaseMissing('notifications', ['type' => BloodRequestSubmitted::class]);
    }

    public function test_decisions_on_a_walk_in_reach_the_hospital_although_nobody_there_submitted_it(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $this->stock($this->centre, $this->prbc, 2);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$response->json('request.id')}/allocate")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->requester->id,
            'type' => BloodRequestDecided::class,
        ]);
    }

    public function test_the_audit_trail_carries_no_personal_details_of_the_watcher_or_verifier(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $context = json_encode(AuditLog::query()->where('action', 'request.walk_in_recorded')->firstOrFail()->context);

        foreach (['Pedro Dela Cruz', '09171234567', '1234-5678-9012', 'Ana Lim', '(082) 222-1234'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) $context);
        }
    }

    public function test_a_walk_in_is_filled_released_and_received_like_any_other_request(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $request = BloodRequest::query()->findOrFail($response->json('request.id'));

        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release", ['handed_to' => 'Pedro Dela Cruz'])
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('received_count', 3);

        $this->assertSame(1, $request->events()->where('event', 'released')->count());
        $this->assertSame(
            ['handed_to' => 'Pedro Dela Cruz'],
            $request->events()->where('event', 'released')->first()->meta
        );
    }

    public function test_the_hospital_may_withdraw_a_walk_in_recorded_for_it(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$response->json('request.id')}/cancel", ['reason' => 'Transferred to another hospital.'])
            ->assertOk();

        $this->assertSame(
            BloodRequestStatus::Cancelled,
            BloodRequest::query()->find($response->json('request.id'))->status
        );
    }

    public function test_the_walk_in_row_is_the_only_place_the_watcher_is_stored(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $walkIn = BloodRequestWalkIn::query()->where('request_id', $response->json('request.id'))->firstOrFail();

        $this->assertSame('Pedro Dela Cruz', $walkIn->representative_name);
        $this->assertSame('philsys', $walkIn->representative_id_type->value);

        $this->assertDatabaseMissing('blood_request_events', ['note' => 'Pedro Dela Cruz']);
        $this->assertDatabaseMissing('audit_logs', ['context' => json_encode(['representative_name' => 'Pedro Dela Cruz'])]);
    }

    public function test_a_custom_issuance_role_may_record_a_walk_in(): void
    {
        $custom = User::factory()->bloodCenterCustomStaff($this->centre, Department::Issuance, 'Issuance Officer')->create();

        $this->actingAs($custom)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();
    }
}
