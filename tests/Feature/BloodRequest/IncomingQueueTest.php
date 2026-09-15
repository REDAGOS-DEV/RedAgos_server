<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
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
 * The fulfilling facility's incoming queue, and reviewing one request in it.
 *
 * Reviewing is read-only: a reviewer may open a request as often as they like
 * without holding a single bag. The availability figure shown beside a request
 * is advisory for the same reason a search result is, and the response says so.
 */
class IncomingQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Facility $centre;

    private Facility $hospital;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->centre, Department::Inventory)->create();
        $this->hospital = Facility::factory()->bloodBank()->approved()->create(['name' => 'St Luke Blood Bank']);

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells']);
        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_lists_only_requests_addressed_to_this_facility(): void
    {
        $this->incoming();

        $elsewhere = Facility::factory()->approved()->create();
        BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($elsewhere)->create();

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requesting_facility.name', 'St Luke Blood Bank');
    }

    public function test_emergencies_come_first_then_the_oldest(): void
    {
        $newerRoutine = $this->incoming(['request_date' => now()->subDay()]);
        $emergency = $this->incoming(['request_date' => now(), 'urgency_level' => 'emergency']);
        $oldestRoutine = $this->incoming(['request_date' => now()->subDays(4)]);

        $ids = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$emergency->id, $oldestRoutine->id, $newerRoutine->id], $ids);
    }

    public function test_the_queue_can_be_filtered_by_status_and_urgency(): void
    {
        $this->incoming();
        $this->incoming(['urgency_level' => 'emergency']);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?urgency_level=emergency')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_emergency', true);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_summary_counts_every_state_including_empty_ones(): void
    {
        $this->incoming();
        $this->incoming(['status' => BloodRequestStatus::Rejected, 'rejection_reason' => 'No stock.']);
        $this->incoming(['urgency_level' => 'emergency']);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests/summary')
            ->assertOk();

        $this->assertSame(2, $response->json('totals.pending'));
        $this->assertSame(1, $response->json('totals.rejected'));
        $this->assertSame(0, $response->json('totals.fulfilled'));
        $this->assertSame(1, $response->json('open_emergencies'));
    }

    public function test_the_summary_is_facility_isolated(): void
    {
        $elsewhere = Facility::factory()->approved()->create();
        BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($elsewhere)->count(3)->create();

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests/summary')
            ->assertOk()
            ->assertJsonPath('totals.pending', 0);
    }

    public function test_reviewing_shows_live_stock_beside_the_request(): void
    {
        $request = $this->incoming(['quantity' => 3]);
        $this->stock(5);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('inventory.available', 5)
            ->assertJsonPath('inventory.outstanding', 3)
            ->assertJsonPath('inventory.can_fully_cover', true)
            ->assertJsonPath('inventory.can_cover_now', 3)
            ->assertJsonPath('inventory.advisory', true);
    }

    public function test_reviewing_reports_a_shortfall_honestly(): void
    {
        $request = $this->incoming(['quantity' => 6]);
        $this->stock(2);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('inventory.available', 2)
            ->assertJsonPath('inventory.can_fully_cover', false)
            ->assertJsonPath('inventory.can_cover_now', 2);
    }

    public function test_reviewing_holds_nothing(): void
    {
        $request = $this->incoming(['quantity' => 2]);
        $this->stock(4);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk();

        $this->assertDatabaseCount('request_allocations', 0);
        $this->assertSame(4, BloodUnit::query()->where('status', BloodUnitStatus::Available)->count());
    }

    public function test_another_facilitys_request_is_not_found(): void
    {
        $elsewhere = Facility::factory()->approved()->create();
        $theirs = BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($elsewhere)->create();

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$theirs->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'request_not_found');
    }

    public function test_staff_without_the_view_ability_are_refused(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();

        $this->actingAs($collection)
            ->getJson('/api/blood-center/blood-requests')
            ->assertForbidden();
    }

    public function test_a_supervisor_reaches_the_queue(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor($this->centre)->create();

        $this->actingAs($supervisor)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function incoming(array $overrides = []): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $overrides['quantity'] ?? 2)
            ->create($overrides);
    }

    private function stock(int $count): void
    {
        $donation = Donation::factory()->create([
            'facility_id' => $this->centre->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->count($count)->create([
            'facility_id' => $this->centre->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
        ]);
    }
}
