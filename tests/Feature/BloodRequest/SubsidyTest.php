<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BillingStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\Billing;
use App\Models\BillingRevision;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\RequestAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Meeting a statement from the government subsidy instead of charging for it.
 *
 * The blood centre is subsidised, so a priced statement can be settled without
 * money changing hands. That is a different fact from "the money was
 * collected", and the two must stay distinguishable — a revenue report that
 * cannot tell them apart claims fees the network never charged.
 */
class SubsidyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $hospital;

    private Facility $centre;

    private User $billingStaff;

    private User $inventoryStaff;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->centre = Facility::factory()->approved()->create();

        $this->billingStaff = User::factory()->bloodCenterStaff($this->centre, Department::Billing)->create();
        $this->inventoryStaff = User::factory()->bloodCenterStaff($this->centre, Department::Issuance)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        // The centre charges, which is what makes the subsidy decision real.
        FacilityBloodComponent::query()->create([
            'facility_id' => $this->centre->id,
            'component_id' => $this->component->id,
            'price' => 1070,
        ]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_a_priced_statement_blocks_release_until_it_is_settled(): void
    {
        $request = $this->reservedRequest();

        $this->assertSame(BillingStatus::Unpaid, $request->billing->status);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_unsettled');
    }

    public function test_applying_the_subsidy_zeroes_the_statement_and_clears_release(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::Subsidised->value)
            ->assertJsonPath('billing.status_label', 'Government Subsidised')
            ->assertJsonPath('billing.total_amount', 0)
            ->assertJsonPath('billing.clears_release', true)
            ->assertJsonPath('billing.is_subsidised', true)
            // The distinction the whole status exists for.
            ->assertJsonPath('billing.represents_collected_money', false);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();
    }

    public function test_a_subsidised_statement_is_not_repriced_by_a_later_allocation(): void
    {
        $request = $this->reservedRequest(units: 2, quantity: 2, allocate: 1);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk();

        // Topping the request up must not revive a balance somebody waived, and
        // must not re-block a release already cleared.
        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $billing = $request->billing()->first();

        $this->assertSame(BillingStatus::Subsidised, $billing->status);
        $this->assertSame('0.00', $billing->total_amount);
    }

    public function test_the_waived_amount_is_recorded_even_though_the_statement_no_longer_carries_it(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy", ['reason' => 'DOH allocation'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'billing.subsidised',
            'actor_id' => $this->billingStaff->id,
        ]);

        $context = json_decode(
            \DB::table('audit_logs')->where('action', 'billing.subsidised')->value('context'),
            true
        );

        $this->assertEquals(1070.0, $context['amount_waived']);
        $this->assertSame('DOH allocation', $context['reason']);
    }

    public function test_a_statement_cannot_be_subsidised_twice(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk();

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_already_subsidised');
    }

    public function test_money_already_collected_survives_a_later_subsidy(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 500,
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk()
            // Deleting the payment would destroy the only record the money was
            // ever received; the refund is settled outside this system.
            ->assertJsonPath('billing.collected', fn ($collected): bool => (float) $collected === 500.0);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_payment_cannot_overturn_the_subsidy(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk();

        // Recording money here used to re-settle the statement as Paid, which
        // erased the decision that the government met the cost.
        $this->actingAs($this->billingStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 500,
                'payment_method' => 'cash',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_settled_by_decision');

        $this->assertSame(BillingStatus::Subsidised, $request->billing()->firstOrFail()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    // --- At the point of issue (project owner, 2026-10-19) ---------------------
    // A subsidised centre may have no billing staff, so Issuance applies the
    // subsidy as it issues, and the request still goes on the hospital's
    // Statement of Account.

    public function test_issuance_applies_the_subsidy_and_the_hospital_gets_a_statement_of_account(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy", ['reason' => 'DOH-subsidised centre'])
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::Subsidised->value)
            ->assertJsonPath('billing.clears_release', true)
            ->assertJsonPath('statement.billing_status', BillingStatus::Subsidised->value)
            ->assertJsonPath('statement.total_amount', '1070.00')
            ->assertJsonPath('statement.amount_due', '0.00')
            ->assertJsonPath('statement.bill_to.facility', $this->hospital->name)
            ->assertJsonPath('statement.lines.0.component_name', 'Packed RBC');

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        // The release changed nothing on it, so it took no second number.
        $this->assertSame(1, BillingRevision::query()->count());

        $this->actingAs(User::factory()->bloodBankStaff($this->hospital)->create())
            ->getJson("/api/hospital/blood-requests/{$request->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::Subsidised->value)
            ->assertJsonCount(1, 'statements')
            ->assertJsonPath('statements.0.billing_status_label', 'Government Subsidised')
            ->assertJsonPath('statements.0.amount_due', '0.00');
    }

    public function test_units_reserved_after_the_subsidy_reach_the_statement_when_they_are_released(): void
    {
        $request = $this->reservedRequest(units: 2, quantity: 2, allocate: 1);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk()
            ->assertJsonPath('statement.lines.0.quantity', 1);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $latest = BillingRevision::query()->with('items')->orderByDesc('revision_number')->firstOrFail();

        $this->assertSame(2, $latest->revision_number);
        $this->assertSame(BillingStatus::Subsidised, $latest->billing_status);
        $this->assertSame(2, (int) $latest->items->sum('quantity'));
        $this->assertSame('2140.00', $latest->total_amount);
        $this->assertSame('0.00', $latest->amount_due);
    }

    public function test_the_dispatch_coordinator_may_apply_it_too(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs(User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create())
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk();
    }

    public function test_only_staff_who_release_or_bill_may_apply_it(): void
    {
        $request = $this->reservedRequest();

        foreach ([StaffRole::ItDataClerk, Department::Collection, Department::Testing] as $post) {
            $this->actingAs(User::factory()->bloodCenterStaff($this->centre, $post)->create())
                ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
                ->assertForbidden();
        }

        $this->assertSame(BillingStatus::Unpaid, $request->billing()->firstOrFail()->status);
    }

    public function test_issuance_still_cannot_take_money(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount' => 1070,
                'payment_method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_billing_staff_can_list_their_facilitys_statements(): void
    {
        $request = $this->reservedRequest();

        $this->actingAs($this->billingStaff)
            ->getJson('/api/blood-center/billings')
            ->assertOk()
            ->assertJsonPath('data.0.request.reference_number', $request->reference_number)
            ->assertJsonPath('data.0.status', BillingStatus::Unpaid->value)
            ->assertJsonPath('data.0.request.components.0', 'Packed RBC x1');
    }

    public function test_another_facilitys_statements_are_not_listed(): void
    {
        $this->reservedRequest();

        $otherCentre = Facility::factory()->approved()->create();
        $stranger = User::factory()->bloodCenterStaff($otherCentre, Department::Billing)->create();

        $this->actingAs($stranger)
            ->getJson('/api/blood-center/billings')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A request with stock held for it and a statement raised.
     */
    private function reservedRequest(int $units = 1, int $quantity = 1, ?int $allocate = null): BloodRequest
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $quantity)
            ->create();

        $donation = Donation::factory()->create([
            'facility_id' => $this->centre->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->count($units)->create([
            'facility_id' => $this->centre->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
        ]);

        $this->actingAs($this->inventoryStaff)
            ->postJson(
                "/api/blood-center/blood-requests/{$request->id}/allocate",
                $allocate === null ? [] : ['quantity' => $allocate]
            )
            ->assertOk();

        return $request->fresh(['billing']);
    }

    /**
     * Guard the fixture: these tests are meaningless if nothing is actually held.
     */
    protected function tearDown(): void
    {
        if ($this->status()->isSuccess()) {
            $this->assertTrue(
                RequestAllocation::query()->where('status', AllocationStatus::Allocated)->exists()
                || Billing::query()->exists(),
                'Every case here needs a statement to act on.'
            );
        }

        parent::tearDown();
    }
}
