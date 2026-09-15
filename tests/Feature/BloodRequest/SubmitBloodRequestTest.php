<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\UrgencyLevel;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Raising a blood request, and what stops one being raised wrongly.
 *
 * The rules worth naming: the requesting facility is never taken from input,
 * a request cannot be addressed to itself or to a facility that has no way to
 * fulfil it, and submitting reserves nothing — approval does that, later.
 */
class SubmitBloodRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $requester;

    private Facility $hospital;

    private Facility $centre;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();
        $this->centre = Facility::factory()->approved()->create(['name' => 'Davao Blood Center']);

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_records_a_pending_request_addressed_to_the_chosen_facility(): void
    {
        $response = $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload(['quantity' => 5]));

        $response->assertCreated()
            ->assertJsonPath('request.status', BloodRequestStatus::Pending->value)
            ->assertJsonPath('request.quantity', 5)
            ->assertJsonPath('request.target_facility.name', 'Davao Blood Center')
            ->assertJsonPath('request.requesting_facility.id', $this->hospital->id)
            ->assertJsonPath('request.outstanding_quantity', 5)
            ->assertJsonPath('request.allocated_count', 0);

        $this->assertDatabaseHas('blood_requests', [
            'facility_id' => $this->hospital->id,
            'target_facility_id' => $this->centre->id,
            'requested_by' => $this->requester->id,
            'status' => BloodRequestStatus::Pending->value,
            'quantity' => 5,
        ]);
    }

    public function test_it_issues_a_reference_number(): void
    {
        $response = $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload());

        $reference = $response->assertCreated()->json('request.reference_number');

        $this->assertSame("RQ-{$this->hospital->id}-0001", $reference);
    }

    public function test_reference_numbers_increment_per_facility(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $otherRequester = User::factory()->bloodBankStaff($otherHospital)->create();

        $first = $this->submit();
        $second = $this->submit();
        $third = $this->actingAs($otherRequester)
            ->postJson('/api/hospital/blood-requests', $this->payload())
            ->json('request.reference_number');

        $this->assertSame("RQ-{$this->hospital->id}-0001", $first);
        $this->assertSame("RQ-{$this->hospital->id}-0002", $second);
        $this->assertSame(
            "RQ-{$otherHospital->id}-0001",
            $third,
            'Each facility numbers its own requests from one.'
        );
    }

    public function test_the_sequence_is_not_derived_lexicographically(): void
    {
        BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->create(['reference_number' => "RQ-{$this->hospital->id}-0100"]);

        $this->assertSame(
            "RQ-{$this->hospital->id}-0101",
            $this->submit(),
            'As a string, 0100 sorts below 0099, so MAX() would reissue a taken number.'
        );
    }

    public function test_the_requesting_facility_is_taken_from_the_account_not_the_payload(): void
    {
        $someoneElse = Facility::factory()->bloodBank()->approved()->create();

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload([
                'facility_id' => $someoneElse->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('request.requesting_facility.id', $this->hospital->id);

        $this->assertDatabaseMissing('blood_requests', ['facility_id' => $someoneElse->id]);
    }

    public function test_a_facility_cannot_send_a_request_to_itself(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload([
                'target_facility_id' => $this->hospital->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_facility_id']);
    }

    public function test_a_request_cannot_be_addressed_to_another_blood_bank(): void
    {
        $otherBank = Facility::factory()->bloodBank()->approved()->create();

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload([
                'target_facility_id' => $otherBank->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_facility_id']);
    }

    public function test_a_request_cannot_be_addressed_to_an_unapproved_facility(): void
    {
        $pending = Facility::factory()->pendingApproval()->create();

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload([
                'target_facility_id' => $pending->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_facility_id']);
    }

    public function test_a_request_cannot_be_addressed_to_a_facility_that_does_not_exist(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload([
                'target_facility_id' => 999999,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_facility_id']);
    }

    public function test_an_invalid_quantity_is_refused(): void
    {
        foreach ([0, -3, 101] as $quantity) {
            $this->actingAs($this->requester)
                ->postJson('/api/hospital/blood-requests', $this->payload(['quantity' => $quantity]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['quantity']);
        }
    }

    public function test_an_unknown_urgency_is_refused(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload(['urgency_level' => 'urgent']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['urgency_level']);
    }

    public function test_an_emergency_request_is_recorded_as_one(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload([
                'urgency_level' => UrgencyLevel::Emergency->value,
            ]))
            ->assertCreated()
            ->assertJsonPath('request.urgency_level', 'emergency')
            ->assertJsonPath('request.urgency_label', 'Emergency');
    }

    public function test_submitting_holds_no_stock(): void
    {
        $this->stockAt($this->centre, 6);

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload(['quantity' => 4]))
            ->assertCreated();

        $this->assertDatabaseCount('request_allocations', 0);
        $this->assertSame(
            6,
            BloodUnit::query()->where('status', BloodUnitStatus::Available)->count(),
            'A submitted request is a question, not a claim. Approval reserves stock, not submission.'
        );
    }

    public function test_submission_writes_an_audit_row(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'request.submitted',
            'actor_id' => $this->requester->id,
            'auditable_type' => BloodRequest::class,
        ]);
    }

    public function test_a_blood_centre_account_cannot_raise_a_request(): void
    {
        $centreStaff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($centreStaff)
            ->postJson('/api/hospital/blood-requests', $this->payload())
            ->assertForbidden();
    }

    public function test_an_unauthenticated_caller_cannot_raise_a_request(): void
    {
        $this->postJson('/api/hospital/blood-requests', $this->payload())->assertUnauthorized();
    }

    public function test_staff_of_an_unapproved_blood_bank_cannot_raise_a_request(): void
    {
        $pendingBank = Facility::factory()->bloodBank()->pendingApproval()->create();
        $staff = User::factory()->bloodBankStaff($pendingBank)->create();

        $this->actingAs($staff)
            ->postJson('/api/hospital/blood-requests', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_approved');
    }

    /**
     * Submit a valid request and return the reference number it was given.
     */
    private function submit(): string
    {
        return $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', $this->payload())
            ->assertCreated()
            ->json('request.reference_number');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'target_facility_id' => $this->centre->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'quantity' => 2,
            'urgency_level' => UrgencyLevel::Routine->value,
            ...$overrides,
        ];
    }

    private function stockAt(Facility $facility, int $count): void
    {
        $donation = Donation::factory()->create([
            'facility_id' => $facility->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->count($count)->create([
            'facility_id' => $facility->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
        ]);
    }
}
