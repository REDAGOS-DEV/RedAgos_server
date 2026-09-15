<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BillingStatus;
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
use App\Notifications\BloodRequestSubmitted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Statements, settlements, and who hears about what.
 *
 * The subsidy runs through all of this: today every statement is zero and
 * settled on creation, so the money path is exercised here by pricing a
 * component deliberately, which is exactly what turning the subsidy off would
 * do in production.
 */
class BillingAndNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $inventoryStaff;

    private User $billingStaff;

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
        $this->inventoryStaff = User::factory()->bloodCenterStaff($this->centre, Department::Inventory)->create();
        $this->billingStaff = User::factory()->bloodCenterStaff($this->centre, Department::Billing)->create();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells', 'price' => 0]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_billing_staff_can_read_a_statement(): void
    {
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->billingStaff)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('billing.total_amount', 0)
            ->assertJsonPath('billing.status', BillingStatus::Paid->value);
    }

    public function test_inventory_staff_can_read_a_statement_but_not_record_payment(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->inventoryStaff)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk();

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 100,
                'payment_method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_a_cash_payment_settles_a_priced_statement(): void
    {
        $this->component->update(['price' => 500]);
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 1000,
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('billing.status', BillingStatus::Paid->value)
            ->assertJsonPath('billing.collected', 1000);
    }

    public function test_a_part_payment_leaves_the_statement_partial(): void
    {
        $this->component->update(['price' => 500]);
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 400,
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('billing.status', BillingStatus::Partial->value)
            ->assertJsonPath('billing.clears_release', false);
    }

    public function test_settling_a_statement_unblocks_release(): void
    {
        $this->component->update(['price' => 500]);
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_unsettled');

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 500,
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();
    }

    public function test_a_gcash_payment_requires_its_reference(): void
    {
        $this->component->update(['price' => 500]);
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 500,
                'payment_method' => 'gcash',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_number']);
    }

    public function test_a_failed_payment_does_not_count_toward_settlement(): void
    {
        $this->component->update(['price' => 500]);
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 500,
                'payment_method' => 'gcash',
                'reference_number' => 'GC-FAILED-1',
                'status' => 'failed',
            ])
            ->assertCreated()
            ->assertJsonPath('billing.collected', 0)
            ->assertJsonPath('billing.status', BillingStatus::Unpaid->value);
    }

    public function test_the_statement_grows_when_more_units_are_held(): void
    {
        $this->component->update(['price' => 500]);
        $request = $this->allocatedRequest(1, askedFor: 3);

        $this->actingAs($this->inventoryStaff)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('billing.total_amount', 500);

        $this->stock(2);
        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($this->inventoryStaff)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('billing.total_amount', 1500);
    }

    public function test_another_facilitys_statement_is_not_found(): void
    {
        $elsewhere = Facility::factory()->approved()->create();
        $theirs = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($elsewhere)
            ->create();

        $this->actingAs($this->billingStaff)
            ->getJson("/api/blood-center/billings/{$theirs->id}")
            ->assertNotFound();
    }

    public function test_submitting_notifies_the_target_facilitys_inventory_staff(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', [
                'target_facility_id' => $this->centre->id,
                'blood_type_id' => $this->bloodType->id,
                'component_id' => $this->component->id,
                'quantity' => 2,
                'urgency_level' => 'emergency',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->inventoryStaff->id,
            'type' => BloodRequestSubmitted::class,
        ]);
    }

    public function test_collection_staff_are_not_notified_of_incoming_requests(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', [
                'target_facility_id' => $this->centre->id,
                'blood_type_id' => $this->bloodType->id,
                'component_id' => $this->component->id,
                'quantity' => 1,
                'urgency_level' => 'routine',
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $collection->id]);
    }

    public function test_staff_can_read_and_clear_their_notification_inbox(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests', [
                'target_facility_id' => $this->centre->id,
                'blood_type_id' => $this->bloodType->id,
                'component_id' => $this->component->id,
                'quantity' => 1,
                'urgency_level' => 'routine',
            ])
            ->assertCreated();

        $this->actingAs($this->inventoryStaff)
            ->getJson('/api/blood-center/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->actingAs($this->inventoryStaff)
            ->getJson('/api/blood-center/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.category', 'request');

        $this->actingAs($this->inventoryStaff)
            ->postJson('/api/blood-center/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_a_requester_reads_only_their_own_notifications(): void
    {
        $this->allocatedRequest(1);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications');

        $stranger = User::factory()->bloodBankStaff()->create();

        $this->actingAs($stranger)
            ->getJson('/api/hospital/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications');
    }

    private function allocatedRequest(int $units, ?int $askedFor = null): BloodRequest
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $askedFor ?? $units)
            ->create();

        $this->stock($units);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => $units])
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
