<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Listing, tracking and withdrawing a blood bank's own requests.
 *
 * Facility isolation is the recurring subject: a blood bank sees the requests
 * it raised and nothing else, and a request belonging to another facility is
 * not found rather than forbidden, so the reply reveals nothing about whether
 * the id exists.
 */
class TrackBloodRequestTest extends TestCase
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
        $this->centre = Facility::factory()->approved()->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_lists_only_the_callers_own_requests(): void
    {
        $this->makeRequest();

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        BloodRequest::factory()->raisedBy($otherHospital)->addressedTo($this->centre)->create();

        $response = $this->actingAs($this->requester)->getJson('/api/hospital/blood-requests');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($this->hospital->id, $response->json('data.0.requesting_facility.id'));
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->makeRequest();
        $this->makeRequest(['status' => BloodRequestStatus::Rejected, 'rejection_reason' => 'No stock.']);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/blood-requests?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'rejected');
    }

    public function test_the_list_reports_coverage_without_loading_allocations(): void
    {
        $request = $this->makeRequest(['quantity' => 3, 'status' => BloodRequestStatus::Processing]);
        RequestAllocation::factory()->holding($request, $this->unitAt($this->centre))->received()->create();
        RequestAllocation::factory()->holding($request, $this->unitAt($this->centre))->create();
        RequestAllocation::factory()->holding($request, $this->unitAt($this->centre))->cancelled()->create();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/blood-requests')
            ->assertOk()
            ->assertJsonPath('data.0.allocated_count', 2)
            ->assertJsonPath('data.0.received_count', 1)
            ->assertJsonPath(
                'data.0.outstanding_quantity',
                1,
                'A listing counts coverage in SQL. Deriving it from an unloaded relation reports zero.'
            );
    }

    public function test_it_shows_one_request_with_its_allocations(): void
    {
        $request = $this->makeRequest(['quantity' => 3, 'status' => BloodRequestStatus::Processing]);
        $unit = $this->unitAt($this->centre);
        RequestAllocation::factory()->holding($request, $unit)->create();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.allocated_count', 1)
            ->assertJsonPath('request.outstanding_quantity', 2)
            ->assertJsonPath('request.received_count', 0)
            ->assertJsonCount(1, 'request.allocations')
            ->assertJsonPath('request.allocations.0.unit_id', $unit->id)
            ->assertJsonPath('request.allocations.0.status', AllocationStatus::Allocated->value);
    }

    public function test_a_cancelled_hold_does_not_count_toward_coverage(): void
    {
        $request = $this->makeRequest(['quantity' => 2, 'status' => BloodRequestStatus::Processing]);
        RequestAllocation::factory()->holding($request, $this->unitAt($this->centre))->cancelled()->create();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.allocated_count', 0)
            ->assertJsonPath('request.outstanding_quantity', 2);
    }

    public function test_a_received_unit_is_counted_as_received(): void
    {
        $request = $this->makeRequest(['quantity' => 2, 'status' => BloodRequestStatus::Processing]);
        RequestAllocation::factory()->holding($request, $this->unitAt($this->centre))->received()->create();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.allocated_count', 1)
            ->assertJsonPath('request.received_count', 1);
    }

    public function test_another_facilitys_request_is_not_found(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $theirs = BloodRequest::factory()->raisedBy($otherHospital)->addressedTo($this->centre)->create();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$theirs->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'request_not_found');
    }

    public function test_it_tracks_a_request_by_reference_number(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/track/{$request->reference_number}")
            ->assertOk()
            ->assertJsonPath('request.reference_number', $request->reference_number);
    }

    public function test_another_facilitys_reference_number_is_not_found(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $theirs = BloodRequest::factory()->raisedBy($otherHospital)->addressedTo($this->centre)->create();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/track/{$theirs->reference_number}")
            ->assertNotFound();
    }

    public function test_a_pending_request_can_be_withdrawn(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/cancel", ['reason' => 'Patient discharged.'])
            ->assertOk()
            ->assertJsonPath('request.status', BloodRequestStatus::Cancelled->value);

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'request.cancelled',
            'actor_id' => $this->requester->id,
        ]);
    }

    public function test_a_request_already_being_processed_cannot_be_withdrawn_by_the_requester(): void
    {
        $request = $this->makeRequest(['status' => BloodRequestStatus::Processing]);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_not_withdrawable');

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Processing->value,
        ]);
    }

    public function test_a_request_cannot_be_withdrawn_twice(): void
    {
        $request = $this->makeRequest(['status' => BloodRequestStatus::Cancelled]);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_not_withdrawable');
    }

    public function test_another_facilitys_request_cannot_be_withdrawn(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $theirs = BloodRequest::factory()->raisedBy($otherHospital)->addressedTo($this->centre)->create();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$theirs->id}/cancel")
            ->assertNotFound();

        $this->assertDatabaseHas('blood_requests', [
            'id' => $theirs->id,
            'status' => BloodRequestStatus::Pending->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeRequest(array $overrides = []): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $overrides['quantity'] ?? 2)
            ->create($overrides);
    }

    private function unitAt(Facility $facility): BloodUnit
    {
        $donation = Donation::factory()->create([
            'facility_id' => $facility->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        return BloodUnit::factory()->create([
            'facility_id' => $facility->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
        ]);
    }
}
