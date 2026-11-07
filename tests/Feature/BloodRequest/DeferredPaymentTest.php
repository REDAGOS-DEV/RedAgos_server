<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BillingStatus;
use App\Enums\Department;
use App\Enums\RequestSource;
use App\Enums\TransactionType;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Issuing a request before its invoice is settled.
 *
 * The project owner's rules, 2026-11-07: staff decide at issuance whether a
 * request needs settling first or can go with deferred payment. A walk-in must
 * be settled first. Every issuance has a billing record either way.
 */
class DeferredPaymentTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(500);
    }

    private function defer(BloodRequest $request, ?User $staff = null): TestResponse
    {
        return $this->actingAs($staff ?? $this->inventoryStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/defer-payment");
    }

    private function release(BloodRequest $request): TestResponse
    {
        return $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release");
    }

    private function walkIn(BloodRequest $request): BloodRequest
    {
        $request->forceFill(['request_source' => RequestSource::BloodCenterWalkIn])->save();

        return $request;
    }

    public function test_an_unpaid_request_waits_until_issuance_decides(): void
    {
        $request = $this->allocatedRequest(1);

        $this->release($request)
            ->assertConflict()
            ->assertJsonPath('code', 'billing_unsettled');
    }

    public function test_a_deferred_request_is_issued_unpaid_and_its_bill_stays_owing(): void
    {
        $request = $this->allocatedRequest(2);

        $this->defer($request)
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::Unpaid->value)
            ->assertJsonPath('billing.is_payment_deferred', true)
            ->assertJsonPath('billing.clears_release', true)
            ->assertJsonPath('billing.collects_payment', true);

        $this->release($request)->assertOk();

        $this->assertSame(0, $request->allocations()->where('status', AllocationStatus::Allocated->value)->count());

        $billing = $request->billing()->first();
        $this->assertSame(BillingStatus::Unpaid, $billing->status);
        $this->assertSame('1000.00', $billing->total_amount);
        $this->assertSame($this->inventoryStaff->id, $billing->payment_deferred_by);

        // The issuance is on record in Billing: the charge is in the journal.
        $this->assertSame(
            1000.0,
            (float) BillingTransaction::query()
                ->where('billing_id', $billing->id)
                ->where('type', TransactionType::Charge->value)
                ->sum('amount')
        );
    }

    public function test_a_deferred_part_payment_can_go_with_the_rest_owing(): void
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 400)->assertCreated();

        $this->defer($request)
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::Partial->value)
            ->assertJsonPath('billing.is_payment_deferred', true);

        $this->release($request)->assertOk();
    }

    public function test_a_deferred_bill_is_settled_at_the_counter_after_the_units_went(): void
    {
        $request = $this->allocatedRequest(2);
        $this->defer($request)->assertOk();
        $this->release($request)->assertOk();

        $this->payCash($request, 1000)
            ->assertCreated()
            ->assertJsonPath('billing.status', BillingStatus::Paid->value)
            ->assertJsonPath('billing.is_payment_deferred', false)
            ->assertJsonPath('billing.clears_release', true);
    }

    public function test_the_counter_keeps_a_deferred_bill_behind_those_holding_blood_back(): void
    {
        $deferred = $this->allocatedRequest(1);
        $this->defer($deferred)->assertOk();
        $this->release($deferred)->assertOk();

        $held = $this->allocatedRequest(1);

        $response = $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/queue')
            ->assertOk();

        $rows = collect($response->json('data'));

        $this->assertSame([$held->id, $deferred->id], $rows->pluck('request_id')->all());

        $row = $rows->firstWhere('request_id', $deferred->id);
        $this->assertTrue($row['takes_payment']);
        $this->assertTrue($row['is_payment_deferred']);
        $this->assertTrue($row['clears_release']);
    }

    public function test_a_walk_in_must_be_settled_first(): void
    {
        $request = $this->walkIn($this->allocatedRequest(1));

        $this->defer($request)
            ->assertConflict()
            ->assertJsonPath('code', 'settlement_required');

        $this->release($request)
            ->assertConflict()
            ->assertJsonPath('code', 'billing_unsettled')
            ->assertJsonPath('message', 'A walk-in request must be fully settled before its units are issued.');

        $this->assertNull($request->billing()->first()->payment_deferred_at);

        $this->payCash($request, 500)->assertCreated();
        $this->release($request)->assertOk();
    }

    public function test_a_request_with_no_billing_record_is_never_issued(): void
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, 1)
            ->create();

        $this->release($request)
            ->assertConflict()
            ->assertJsonPath('code', 'billing_missing');

        $this->defer($request)
            ->assertNotFound()
            ->assertJsonPath('code', 'billing_missing');
    }

    public function test_only_a_bill_still_held_back_can_be_deferred(): void
    {
        $paid = $this->allocatedRequest(1);
        $this->payCash($paid, 500)->assertCreated();
        $this->defer($paid)->assertConflict()->assertJsonPath('code', 'billing_settled');

        $weekly = $this->allocatedRequest(1, replenishment: true);
        $this->defer($weekly)->assertConflict()->assertJsonPath('code', 'billing_settled');

        $once = $this->allocatedRequest(1);
        $this->defer($once)->assertOk();
        $this->defer($once)->assertConflict()->assertJsonPath('code', 'payment_already_deferred');
    }

    public function test_the_decision_is_issuances_and_this_centres(): void
    {
        $request = $this->allocatedRequest(1);

        $this->defer($request, $this->billingClerk)->assertForbidden();

        $otherCentre = Facility::factory()->approved()->create();
        $stranger = User::factory()->bloodCenterStaff($otherCentre, Department::Issuance)->create();
        $this->defer($request, $stranger)->assertNotFound();

        $this->assertNull($request->billing()->first()->payment_deferred_at);
    }

    public function test_the_decision_is_audited(): void
    {
        $request = $this->allocatedRequest(2);

        $this->defer($request)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'billing.payment_deferred',
            'actor_id' => $this->inventoryStaff->id,
        ]);
    }

    public function test_deferring_moves_the_request_to_for_dispatch(): void
    {
        $deferred = $this->allocatedRequest(1);
        $owing = $this->allocatedRequest(1);
        $this->defer($deferred)->assertOk();

        $cleared = $this->actingAs($this->inventoryStaff)
            ->getJson('/api/blood-center/blood-requests?release_cleared=1')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$deferred->id], $cleared);

        $stillOwing = $this->actingAs($this->inventoryStaff)
            ->getJson('/api/blood-center/blood-requests?release_cleared=0')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$owing->id], $stillOwing);
    }

    public function test_an_invoice_says_when_it_is_payable(): void
    {
        $request = $this->allocatedRequest(1);

        $before = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertSuccessful()
            ->assertJsonPath('revision.payment_deferred', false)
            ->json('revision.id');

        $this->defer($request)->assertOk();

        // A deferral changes what the invoice says, so it is frozen anew.
        $after = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertSuccessful()
            ->assertJsonPath('created', true)
            ->assertJsonPath('revision.payment_deferred', true)
            ->json('revision.id');

        $this->assertNotSame($before, $after);

        // Still true of the bill once it is paid.
        $this->payCash($request, 500)->assertCreated();

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertSuccessful()
            ->assertJsonPath('revision.payment_deferred', true);
    }
}
