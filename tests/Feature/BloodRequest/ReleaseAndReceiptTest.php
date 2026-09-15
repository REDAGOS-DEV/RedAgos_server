<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Models\Billing;
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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Dispatch, receipt, and the payment gate between them.
 *
 * Two separations carry this file. Releasing a unit is not receiving it — only
 * the hospital can confirm arrival — and a request is not fulfilled until every
 * unit it asked for has actually been confirmed.
 */
class ReleaseAndReceiptTest extends TestCase
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

    public function test_releasing_issues_the_units_and_marks_the_holds_released(): void
    {
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk()
            ->assertJsonCount(2, 'released_units');

        $this->assertSame(2, BloodUnit::query()->where('status', BloodUnitStatus::Issued)->count());
        $this->assertSame(0, BloodUnit::query()->where('status', BloodUnitStatus::Reserved)->count());
        $this->assertSame(
            2,
            RequestAllocation::query()->where('request_id', $request->id)->released()->count()
        );
    }

    public function test_releasing_does_not_assert_the_units_arrived(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->assertNull(
            RequestAllocation::query()->where('request_id', $request->id)->first()->received_at,
            'Only the receiving facility can say a bag arrived.'
        );
        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Processing->value,
        ]);
    }

    public function test_release_is_refused_while_a_priced_statement_is_unpaid(): void
    {
        $request = $this->allocatedRequest(1);
        $request->billing()->update(['total_amount' => 900, 'status' => 'unpaid']);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_unsettled');

        $this->assertSame(
            1,
            BloodUnit::query()->where('status', BloodUnitStatus::Reserved)->count(),
            'No unit leaves the building without confirmed payment.'
        );
    }

    public function test_release_is_refused_while_a_statement_is_only_part_paid(): void
    {
        $request = $this->allocatedRequest(1);
        $request->billing()->update(['total_amount' => 900, 'status' => 'partial']);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_unsettled');
    }

    public function test_release_proceeds_once_the_statement_is_settled(): void
    {
        $request = $this->allocatedRequest(1);
        $request->billing()->update(['total_amount' => 900, 'status' => 'paid']);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();
    }

    public function test_release_proceeds_under_the_subsidy_without_any_payment_step(): void
    {
        $request = $this->allocatedRequest(2);

        $this->assertSame(0.0, (float) $request->billing()->first()->total_amount);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_request_with_nothing_held_cannot_be_released(): void
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, 2)
            ->create();
        Billing::factory()->create(['request_id' => $request->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_release');
    }

    public function test_confirming_receipt_of_every_unit_fulfils_the_request(): void
    {
        $request = $this->releasedRequest(2);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Fulfilled->value);

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request->id,
            'status' => BloodRequestStatus::Fulfilled->value,
        ]);
        $this->assertNotNull($request->fresh()->fulfilled_at);
    }

    public function test_confirming_fewer_units_than_asked_leaves_the_request_partial(): void
    {
        $request = $this->releasedRequest(2, askedFor: 5);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value);

        $this->assertNull(
            $request->fresh()->fulfilled_at,
            'A partly filled request has not been fulfilled.'
        );
    }

    public function test_a_partial_request_can_still_be_topped_up(): void
    {
        $request = $this->releasedRequest(1, askedFor: 3);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('status', BloodRequestStatus::Partial->value);

        $this->stock(2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 3);
    }

    public function test_only_named_units_are_confirmed_when_a_delivery_arrives_short(): void
    {
        $request = $this->releasedRequest(3);
        $allocations = RequestAllocation::query()->where('request_id', $request->id)->pluck('id')->all();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt", [
                'allocation_ids' => [$allocations[0]],
            ])
            ->assertOk();

        $this->assertSame(
            1,
            RequestAllocation::query()->where('request_id', $request->id)->received()->count()
        );
    }

    public function test_receipt_cannot_be_confirmed_before_dispatch(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_confirm');
    }

    public function test_receipt_cannot_be_confirmed_twice(): void
    {
        $request = $this->releasedRequest(1);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_confirm');
    }

    public function test_the_releasing_facility_cannot_confirm_receipt_on_the_hospitals_behalf(): void
    {
        $request = $this->releasedRequest(1);

        $this->actingAs($this->staff)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertForbidden();
    }

    public function test_another_hospital_cannot_confirm_receipt(): void
    {
        $request = $this->releasedRequest(1);
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $stranger = User::factory()->bloodBankStaff($otherHospital)->create();

        $this->actingAs($stranger)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertNotFound();
    }

    public function test_staff_without_the_release_ability_cannot_dispatch(): void
    {
        $request = $this->allocatedRequest(1);
        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();

        $this->actingAs($collection)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertForbidden();
    }

    public function test_release_notifies_the_requester(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->requester->id,
            'type' => BloodRequestDecided::class,
        ]);
    }

    /**
     * A request with stock already held for it.
     */
    private function allocatedRequest(int $units, ?int $askedFor = null): BloodRequest
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $askedFor ?? $units)
            ->create();

        $this->stock($units);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => $units])
            ->assertOk();

        return $request->fresh();
    }

    /**
     * A request whose held stock has been dispatched.
     */
    private function releasedRequest(int $units, ?int $askedFor = null): BloodRequest
    {
        $request = $this->allocatedRequest($units, $askedFor);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        return $request->fresh();
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
