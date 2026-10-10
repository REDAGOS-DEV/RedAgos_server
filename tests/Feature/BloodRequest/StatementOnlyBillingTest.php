<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BillingStatus;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\Payment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * A weekly (replenishment) order is billed by statement only.
 *
 * Decided by the project owner on 2026-10-10: only the patient or watcher of a
 * Patient Transfusion owes money in RedAgos. A weekly order's statement goes
 * to the hospital and is settled outside the system, so nothing is collected
 * against it here and it never holds units back.
 */
class StatementOnlyBillingTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(500);
    }

    public function test_a_replenishment_request_is_billed_by_statement_only(): void
    {
        $request = $this->allocatedRequest(2, replenishment: true);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::StatementOnly->value)
            ->assertJsonPath('billing.total_amount', 1000)
            ->assertJsonPath('billing.is_statement_only', true)
            ->assertJsonPath('billing.collects_payment', false)
            ->assertJsonPath('billing.clears_release', true)
            ->assertJsonPath('billing.represents_collected_money', false);
    }

    public function test_a_statement_only_request_is_released_without_any_payment(): void
    {
        $request = $this->allocatedRequest(1, replenishment: true);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();
    }

    public function test_no_payment_is_recorded_against_a_statement_only_statement(): void
    {
        $request = $this->allocatedRequest(1, replenishment: true);

        $this->payCash($request, 500)
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_not_collectible');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_statement_only_statement_cannot_be_subsidised(): void
    {
        $request = $this->allocatedRequest(1, replenishment: true);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_not_collectible');
    }

    public function test_its_total_follows_the_units_reserved_while_its_status_stays(): void
    {
        $request = $this->allocatedRequest(1, askedFor: 3, replenishment: true);

        $this->topUp($request, 2);

        $billing = $request->billing()->firstOrFail();

        $this->assertSame('1500.00', $billing->total_amount);
        $this->assertSame(BillingStatus::StatementOnly, $billing->status);
    }

    public function test_it_is_not_counted_as_outstanding(): void
    {
        $this->allocatedRequest(1, replenishment: true);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billings?outstanding=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_patient_transfusion_still_has_to_be_paid(): void
    {
        $request = $this->allocatedRequest(1);

        $this->assertSame(BillingStatus::Unpaid, $request->billing()->firstOrFail()->status);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_unsettled');
    }

    public function test_the_backfill_marks_existing_weekly_statements_and_leaves_paid_ones_for_a_person(): void
    {
        $weekly = BloodRequest::factory()->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)->replenishment()->create();
        $weeklyPaid = BloodRequest::factory()->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)->replenishment()->create();
        $transfusion = BloodRequest::factory()->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)->create();

        $unmarked = Billing::factory()->chargeable(500)->create(['request_id' => $weekly->id]);
        $withMoney = Billing::factory()->paid(500)->create(['request_id' => $weeklyPaid->id]);
        $payable = Billing::factory()->chargeable(500)->create(['request_id' => $transfusion->id]);
        Payment::factory()->create(['billing_id' => $withMoney->id, 'amount_paid' => 500]);

        (require database_path('migrations/2026_10_10_001849_mark_replenishment_statements_statement_only.php'))->up();

        $this->assertSame(BillingStatus::StatementOnly, $unmarked->refresh()->status);
        $this->assertSame(BillingStatus::Paid, $withMoney->refresh()->status, 'Money already collected is left for a person to decide.');
        $this->assertSame(BillingStatus::Unpaid, $payable->refresh()->status);
    }
}
