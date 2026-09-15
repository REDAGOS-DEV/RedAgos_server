<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Notifications\BloodRequestDecided;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Holding stock for a request, and everything that must not happen while doing so.
 *
 * The rules this pins down: a hold never exceeds what was asked or what is on
 * the shelf, the oldest bag goes first, a bag already promised elsewhere is
 * untouchable, and approving is the moment stock moves — not submitting.
 */
class AllocateUnitsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Facility $centre;

    private Facility $hospital;

    private User $requester;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->centre, Department::Inventory)->create();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create([
            'name' => 'Packed Red Blood Cells',
            'price' => 0,
        ]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_holds_stock_and_moves_the_request_to_processing(): void
    {
        $request = $this->incomingRequest(3);
        $this->stock(5);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 3)
            ->assertJsonPath('short_by', 0);

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Processing->value,
            'reviewed_by' => $this->staff->id,
        ]);
        $this->assertSame(3, RequestAllocation::query()->where('request_id', $request->id)->count());
        $this->assertSame(3, BloodUnit::query()->where('status', BloodUnitStatus::Reserved)->count());
        $this->assertSame(2, BloodUnit::query()->where('status', BloodUnitStatus::Available)->count());
    }

    public function test_it_never_holds_more_than_the_request_asked_for(): void
    {
        $request = $this->incomingRequest(2);
        $this->stock(10);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => 9])
            ->assertOk()
            ->assertJsonPath('held_total', 2);

        $this->assertSame(
            2,
            BloodUnit::query()->where('status', BloodUnitStatus::Reserved)->count(),
            'A reviewer asking for more than the request needs must not drain the shelf.'
        );
    }

    public function test_it_never_holds_more_than_the_shelf_has(): void
    {
        $request = $this->incomingRequest(5);
        $this->stock(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 2)
            ->assertJsonPath('short_by', 3);

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Processing->value,
        ]);
    }

    public function test_the_oldest_units_are_held_first(): void
    {
        $request = $this->incomingRequest(2);
        $newest = $this->unit(['id' => 'U-NEW', 'expiry_date' => $this->inDays(40)]);
        $oldest = $this->unit(['id' => 'U-OLD', 'expiry_date' => $this->inDays(3)]);
        $middle = $this->unit(['id' => 'U-MID', 'expiry_date' => $this->inDays(20)]);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->assertSame(['U-OLD', 'U-MID'], $response->json('allocated_units'));
        $this->assertSame(BloodUnitStatus::Available, $newest->fresh()->status);
    }

    public function test_a_unit_already_held_by_another_request_is_not_taken(): void
    {
        $firstRequest = $this->incomingRequest(1);
        $secondRequest = $this->incomingRequest(1);
        $unit = $this->unit(['id' => 'ONLY-ONE']);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$firstRequest->id}/allocate")
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$secondRequest->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_matching_stock');

        $this->assertSame(
            1,
            RequestAllocation::query()->where('unit_id', 'ONLY-ONE')->claiming()->count(),
            'One bag, one claim.'
        );
    }

    public function test_an_expired_unit_is_never_held(): void
    {
        $request = $this->incomingRequest(2);
        $this->unit(['id' => 'FRESH']);
        $this->unit(['id' => 'STALE', 'expiry_date' => $this->inDays(-1)]);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->assertSame(['FRESH'], $response->json('allocated_units'));
    }

    public function test_a_reserved_or_discarded_unit_is_never_held(): void
    {
        $request = $this->incomingRequest(3);
        $this->unit(['id' => 'OK']);
        $this->unit(['id' => 'HELD', 'status' => BloodUnitStatus::Reserved]);
        $this->unit(['id' => 'GONE', 'status' => BloodUnitStatus::Discarded]);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->assertSame(['OK'], $response->json('allocated_units'));
    }

    public function test_stock_of_the_wrong_type_or_component_is_never_held(): void
    {
        $request = $this->incomingRequest(3);
        $otherType = BloodType::firstOrCreate(['code' => 'A-'], ['label' => 'A-']);
        $otherComponent = BloodComponent::factory()->create(['name' => 'Platelets']);

        $this->unit(['id' => 'MATCH']);
        $this->unit(['id' => 'WRONG-TYPE', 'blood_type_id' => $otherType->id]);
        $this->unit(['id' => 'WRONG-COMP', 'component_id' => $otherComponent->id]);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->assertSame(['MATCH'], $response->json('allocated_units'));
    }

    public function test_another_facilitys_stock_is_never_held(): void
    {
        $request = $this->incomingRequest(2);
        $elsewhere = Facility::factory()->approved()->create();
        $this->unit(['id' => 'THEIRS'], $elsewhere);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_matching_stock');
    }

    public function test_topping_up_a_partly_held_request_stops_at_the_requested_total(): void
    {
        $request = $this->incomingRequest(4);
        $this->stock(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 2);

        $this->stock(5);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 4)
            ->assertJsonPath('short_by', 0);

        $this->assertSame(4, RequestAllocation::query()->where('request_id', $request->id)->claiming()->count());
    }

    public function test_a_fully_held_request_cannot_be_allocated_again(): void
    {
        $request = $this->incomingRequest(1);
        $this->stock(3);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_fully_allocated');
    }

    public function test_allocation_raises_a_zero_rated_statement_under_the_subsidy(): void
    {
        $request = $this->incomingRequest(2);
        $this->stock(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('billing.total_amount', 0)
            ->assertJsonPath('billing.status', 'paid')
            ->assertJsonPath('billing.is_zero_rated', true)
            ->assertJsonPath('billing.clears_release', true);

        $this->assertDatabaseHas('billings', ['request_id' => $request->id, 'total_amount' => 0]);
    }

    public function test_a_priced_component_produces_an_unpaid_statement(): void
    {
        $this->component->update(['price' => 750.00]);
        $request = $this->incomingRequest(2);
        $this->stock(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('billing.total_amount', 1500)
            ->assertJsonPath('billing.status', 'unpaid')
            ->assertJsonPath(
                'billing.clears_release',
                false,
                'Pricing the component is all it should take to turn the release gate on.'
            );
    }

    public function test_a_request_can_be_rejected_with_a_reason(): void
    {
        $request = $this->incomingRequest(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/reject", [
                'reason' => 'No O+ packed cells in stock until Thursday.',
            ])
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Rejected->value);

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Rejected->value,
            'rejection_reason' => 'No O+ packed cells in stock until Thursday.',
            'reviewed_by' => $this->staff->id,
        ]);
    }

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $request = $this->incomingRequest(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_a_request_holding_stock_cannot_be_rejected(): void
    {
        $request = $this->incomingRequest(1);
        $this->stock(1);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/reject", ['reason' => 'Changed our mind.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_has_holds');
    }

    public function test_giving_up_a_hold_returns_the_unit_and_reopens_the_request(): void
    {
        $request = $this->incomingRequest(1);
        $this->stock(1);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release-holds", [
                'reason' => 'Courier cancelled; returning stock.',
            ])
            ->assertOk()
            ->assertJsonPath('units_returned', 1);

        $this->assertSame(1, BloodUnit::query()->where('status', BloodUnitStatus::Available)->count());
        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Pending->value,
        ]);
        $this->assertSame(
            AllocationStatus::Cancelled,
            RequestAllocation::query()->where('request_id', $request->id)->first()->status
        );
    }

    public function test_a_returned_unit_can_be_held_by_a_different_request(): void
    {
        $first = $this->incomingRequest(1);
        $second = $this->incomingRequest(1);
        $this->unit(['id' => 'RECYCLED']);

        $this->actingAs($this->staff)->postJson("/api/blood-center/blood-requests/{$first->id}/allocate")->assertOk();
        $this->actingAs($this->staff)->postJson("/api/blood-center/blood-requests/{$first->id}/release-holds", [
            'reason' => 'Returned to stock.',
        ])->assertOk();

        $response = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$second->id}/allocate")
            ->assertOk();

        $this->assertSame(['RECYCLED'], $response->json('allocated_units'));
    }

    public function test_a_cancelled_request_cannot_be_allocated(): void
    {
        $request = $this->incomingRequest(2, ['status' => BloodRequestStatus::Cancelled]);
        $this->stock(3);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'request_closed');
    }

    public function test_another_facilitys_request_cannot_be_allocated(): void
    {
        $elsewhere = Facility::factory()->approved()->create();
        $theirs = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($elsewhere)
            ->forStock($this->bloodType, $this->component, 2)
            ->create();
        $this->stock(3);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$theirs->id}/allocate")
            ->assertNotFound()
            ->assertJsonPath('code', 'request_not_found');
    }

    public function test_allocation_notifies_the_requester(): void
    {
        $request = $this->incomingRequest(1);
        $this->stock(1);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->requester->id,
            'type' => BloodRequestDecided::class,
        ]);
    }

    public function test_allocation_writes_an_audit_row_per_unit(): void
    {
        $request = $this->incomingRequest(2);
        $this->stock(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'allocation.reserved')->count()
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'request.allocated',
            'actor_id' => $this->staff->id,
        ]);
    }

    public function test_staff_without_the_approve_ability_cannot_allocate(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();
        $request = $this->incomingRequest(1);
        $this->stock(1);

        $this->actingAs($collection)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertForbidden();
    }

    public function test_a_blood_bank_account_cannot_reach_the_fulfilling_routes(): void
    {
        $request = $this->incomingRequest(1);

        $this->actingAs($this->requester)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function incomingRequest(int $quantity, array $overrides = []): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $quantity)
            ->create($overrides);
    }

    private function stock(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->unit([]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function unit(array $overrides = [], ?Facility $facility = null): BloodUnit
    {
        $facility ??= $this->centre;

        $donation = Donation::factory()->create([
            'facility_id' => $facility->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        return BloodUnit::factory()->create([
            'facility_id' => $facility->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
            ...$overrides,
        ]);
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
