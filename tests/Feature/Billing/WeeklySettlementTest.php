<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingStatus;
use App\Models\BillingRevision;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Service\StatementRevisionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Recording that a hospital settled a weekly bill outside RedAgos.
 *
 * What is locked in: only a statement-only bill, and only once its order is
 * closed, so the figure settled is final; it is recorded once, with the
 * hospital's reference and date; no money moves, nothing is collected, the
 * journal settles the balance; the hospital sees it; and its figure no longer
 * follows anything.
 */
class WeeklySettlementTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_billing_staff_record_the_hospitals_settlement_of_a_dispatched_weekly_bill(): void
    {
        $request = $this->dispatchedWeekly(2);

        $this->settle($request)
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::SettledOutside->value)
            ->assertJsonPath('billing.is_statement_only', true)
            ->assertJsonPath('billing.is_settled_outside', true)
            ->assertJsonPath('billing.clears_release', true)
            ->assertJsonPath('billing.collects_payment', false)
            ->assertJsonPath('billing.settlement.reference', 'HOSP-CHK-0042')
            ->assertJsonPath('billing.settlement.settled_at', '2026-10-09');

        $row = BillingTransaction::query()->where('type', 'external_settlement')->sole();
        $this->assertSame('-1800.00', $row->amount);
        $this->assertSame('0.00', $row->balance_after);
        $this->assertSame('outside', $row->channel->value);
        $this->assertSame('weekly_replenishment', $row->category->value);
        $this->assertSame('HOSP-CHK-0042', $row->reference);

        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.weekly_settled', 'actor_id' => $this->billingClerk->id]);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::SettledOutside->value)
            ->assertJsonPath('billing.settlement.reference', 'HOSP-CHK-0042');
    }

    public function test_it_is_recorded_once(): void
    {
        $request = $this->dispatchedWeekly(1);

        $this->settle($request)->assertOk();
        $this->settle($request)->assertStatus(409)->assertJsonPath('code', 'weekly_already_settled');
    }

    public function test_a_weekly_order_still_open_cannot_be_settled_yet(): void
    {
        $request = $this->allocatedRequest(1, replenishment: true);

        $this->settle($request)->assertStatus(409)->assertJsonPath('code', 'weekly_not_final');
    }

    public function test_a_patient_bill_is_never_settled_this_way(): void
    {
        $request = $this->allocatedRequest(1);

        $this->settle($request)->assertStatus(409)->assertJsonPath('code', 'not_a_weekly_statement');
    }

    public function test_the_reference_is_required_and_the_date_cannot_be_in_the_future(): void
    {
        $request = $this->dispatchedWeekly(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/settlement", ['settled_at' => now()->addDays(3)->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settlement_reference', 'settled_at']);
    }

    public function test_a_settled_bill_takes_no_payment_and_its_statement_says_it_was_settled(): void
    {
        $request = $this->dispatchedWeekly(1);
        $this->settle($request)->assertOk();

        $this->payCash($request, 900)->assertStatus(409)->assertJsonPath('code', 'billing_not_collectible');

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_not_collectible');

        $revisionId = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertCreated()
            ->assertJsonPath('revision.billing_status', 'settled_outside')
            ->assertJsonPath('revision.statement_only', true)
            ->assertJsonPath('revision.amount_due', '0.00')
            ->json('revision.id');

        $html = view('pdf.billing-statement', app(StatementRevisionService::class)->viewData(BillingRevision::query()->findOrFail($revisionId)))->render();

        $this->assertStringContainsString('settled by the requesting hospital outside RedAgos', $html);
        $this->assertStringContainsString('HOSP-CHK-0042', $html);
    }

    public function test_the_weekly_and_patient_bills_are_listed_apart(): void
    {
        $this->allocatedRequest(1);
        $weekly = $this->allocatedRequest(1, replenishment: true);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billings?category=weekly')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request.id', $weekly->id);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billings?category=patient')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * A weekly (replenishment) order with every unit reserved and dispatched, so it is closed.
     */
    private function dispatchedWeekly(int $units): BloodRequest
    {
        $request = $this->allocatedRequest($units, replenishment: true);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->assertTrue($request->fresh()->isClosed());

        return $request;
    }

    private function settle(BloodRequest $request)
    {
        return $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/settlement", [
                'settlement_reference' => 'HOSP-CHK-0042',
                'settled_at' => '2026-10-09',
                'settlement_note' => 'Cheque deposited.',
            ]);
    }
}
