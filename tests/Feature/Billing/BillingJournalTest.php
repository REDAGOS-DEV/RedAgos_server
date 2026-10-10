<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Enums\StaffRole;
use App\Models\Billing;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Service\BillingLedger;
use App\Service\BillingService;
use App\Service\StatementFigures;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionMethod;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * The billing journal: one numbered, categorised row for every money event.
 *
 * What is locked in: every write to a bill posts exactly one row (two for a
 * corrected payment method), signed against the bill, with the running
 * balance; every bill's rows add up to what it owes; rows never change; and
 * the list, export and summary read only the caller's centre.
 *
 * Run with cash shifts on, so the rows can be read back through a shift's
 * drawer; without them a row carries no shift (CounterWithoutShiftsTest).
 */
class BillingJournalTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['blood_center.cash_shifts' => true]);
        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_each_money_event_on_a_bill_is_one_numbered_row_with_the_running_balance(): void
    {
        $request = $this->allocatedRequest(1, askedFor: 2);
        $this->topUp($request, 1);
        $this->payCash($request, 500)->assertCreated();

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy", ['reason' => 'PCSO-funded.'])
            ->assertOk();

        $rows = BillingTransaction::query()->where('request_id', $request->id)->orderBy('id')->get();

        $this->assertSame(
            [['charge', '900.00', '900.00'], ['charge', '900.00', '1800.00'], ['payment', '-500.00', '1300.00'], ['subsidy', '-1800.00', '-500.00']],
            $rows->map(fn (BillingTransaction $row): array => [$row->type->value, $row->amount, $row->balance_after])->all()
        );

        $this->assertSame(
            ["TXN-{$this->centre->id}-000001", "TXN-{$this->centre->id}-000002", "TXN-{$this->centre->id}-000003", "TXN-{$this->centre->id}-000004"],
            $rows->pluck('transaction_number')->all()
        );

        $payment = $rows[2];
        $this->assertSame('patient_transfusion', $payment->category->value);
        $this->assertSame('counter', $payment->channel->value);
        $this->assertSame('cash', $payment->payment_method->value);
        $this->assertNotNull($payment->payment_receipt_id);
        $this->assertNotNull($payment->cash_session_id);
        $this->assertSame($this->billingClerk->id, $payment->recorded_by);

        // After the subsidy the 500 already paid is a credit, settled outside RedAgos.
        $this->assertTrue($this->drift()->isEmpty());
    }

    public function test_a_weekly_bill_is_journalled_in_its_own_category(): void
    {
        $request = $this->allocatedRequest(1, replenishment: true);

        $row = BillingTransaction::query()->where('request_id', $request->id)->sole();

        $this->assertSame('weekly_replenishment', $row->category->value);
        $this->assertSame('charge', $row->type->value);
        $this->assertSame('system', $row->channel->value);
    }

    public function test_a_corrected_amount_posts_its_difference_and_a_corrected_method_moves_the_whole_payment(): void
    {
        $request = $this->allocatedRequest(2); // 1,800
        $paymentId = $this->payCash($request, 1000)->assertCreated()->json('receipt.id');
        $payment = Payment::query()->whereHas('receipts', fn ($q) => $q->whereKey($paymentId))->sole();

        $this->approveCorrection($payment, ['amount_paid' => 800, 'payment_method' => 'cash', 'reference_number' => null]);

        $correction = BillingTransaction::query()->where('type', 'payment_correction')->sole();
        $this->assertSame('200.00', $correction->amount);
        $this->assertSame('cash', $correction->payment_method->value);
        $this->assertNotNull($correction->correction_request_id);

        $this->approveCorrection($payment, ['amount_paid' => 800, 'payment_method' => 'gcash', 'reference_number' => 'GC-FIX-1']);

        $moved = BillingTransaction::query()->where('type', 'payment_correction')->orderBy('id')->get()->slice(1)->values();
        $this->assertSame([['800.00', 'cash'], ['-800.00', 'gcash']], $moved->map(fn ($row): array => [$row->amount, $row->payment_method->value])->all());

        // The shift's drawer no longer counts the 800 as cash.
        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertJsonPath('session.figures.cash_collected', '0.00')
            ->assertJsonPath('session.figures.gcash_counter', '800.00');

        $this->assertTrue($this->drift()->isEmpty());
    }

    public function test_a_gateway_payment_is_journalled_as_the_checkout_that_took_it(): void
    {
        $request = $this->allocatedRequest(1);
        $shift = $this->shiftFor($this->billingClerk);
        $revisionId = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->json('revision.id');

        $attempt = PaymentAttempt::query()->create([
            'billing_id' => $request->billing()->value('id'),
            'billing_revision_id' => $revisionId,
            'initiated_by' => $this->billingClerk->id,
            'initiator_facility_id' => $this->centre->id,
            'cash_session_id' => $shift->id,
            'provider' => 'xendit',
            'provider_account_id' => 'sub-account-test',
            'reference_id' => 'RA-TEST-GW',
            'amount_centavos' => 90000,
            'currency' => 'PHP',
            'payer_name' => 'Ana',
            'status' => PaymentAttemptStatus::Active,
        ]);

        app(BillingService::class)->confirmGatewayPayment($attempt, 'pay_123');

        $row = BillingTransaction::query()->where('type', 'payment')->sole();
        $this->assertSame('gateway', $row->channel->value);
        $this->assertSame('gcash', $row->payment_method->value);
        $this->assertSame($attempt->id, $row->payment_attempt_id);
        $this->assertSame($shift->id, $row->cash_session_id);
        $this->assertSame('pay_123', $row->reference);
        $this->assertNull($row->recorded_by);
    }

    public function test_a_journal_row_never_changes(): void
    {
        $this->allocatedRequest(1);
        $row = BillingTransaction::query()->firstOrFail();

        try {
            $row->update(['note' => 'Edited.']);
            $this->fail('The model let a journal row change.');
        } catch (LogicException) {
        }

        try {
            DB::table('billing_transactions')->where('id', $row->id)->update(['amount' => '1.00']);
            $this->fail('The database let a journal row change.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        DB::table('billing_transactions')->where('id', $row->id)->delete();
    }

    public function test_the_journal_lists_filters_and_totals_this_centres_rows_only(): void
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 1000)->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billing-transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'payment')
            ->assertJsonPath('data.0.direction', 'credit')
            ->assertJsonPath('data.0.request.reference_number', $request->reference_number)
            ->assertJsonPath('totals.count', 2)
            ->assertJsonPath('totals.charged', '1800.00')
            ->assertJsonPath('totals.collected', '1000.00');

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billing-transactions?type=charge')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('totals.collected', '0.00');

        $elsewhere = Facility::factory()->approved()->create();
        $stranger = User::factory()->bloodCenterStaff($elsewhere, StaffRole::BillingClerk)->create();

        $this->actingAs($stranger)->getJson('/api/blood-center/billing-transactions')->assertOk()->assertJsonCount(0, 'data');

        // The rows carry payment references, so billing.view alone does not read them.
        $this->actingAs($this->inventoryStaff)->getJson('/api/blood-center/billing-transactions')->assertForbidden();
    }

    public function test_the_journal_exports_as_csv(): void
    {
        $request = $this->allocatedRequest(1);
        $this->payCash($request, 900)->assertCreated();

        $csv = $this->actingAs($this->billingClerk)
            ->get('/api/blood-center/billing-transactions/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('Transaction,Date,Category,Type', $lines[0]);
        $this->assertStringContainsString('Payment', $lines[2]);
        $this->assertStringContainsString($request->reference_number, $lines[2]);
    }

    public function test_the_summary_is_counted_on_the_server(): void
    {
        $owing = $this->allocatedRequest(2);
        $paid = $this->allocatedRequest(1);
        $this->payCash($paid, 900)->assertCreated();
        $this->payCash($owing, 300)->assertCreated();
        $this->allocatedRequest(1, replenishment: true);

        $this->actingAs($this->inventoryStaff)
            ->getJson('/api/blood-center/billings/summary')
            ->assertOk()
            ->assertJsonPath('outstanding.count', 1)
            ->assertJsonPath('outstanding.amount', '1500.00')
            ->assertJsonPath('collected_today', '1200.00')
            ->assertJsonPath('collected_this_month', '1200.00')
            ->assertJsonPath('weekly_awaiting_settlement.count', 1)
            ->assertJsonPath('weekly_awaiting_settlement.amount', '900.00')
            ->assertJsonPath('issuer.name', $this->centre->name)
            ->assertJsonPath('issuer.logo_url', null);
    }

    public function test_bills_from_before_the_journal_are_carried_in_so_they_add_up(): void
    {
        $request = BloodRequest::factory()->raisedBy($this->hospital, $this->requester)->addressedTo($this->centre)->create();
        $billing = Billing::factory()->paid(1500)->create(['request_id' => $request->id]);
        Payment::factory()->create(['billing_id' => $billing->id, 'amount_paid' => 1000]);
        Payment::factory()->create(['billing_id' => $billing->id, 'amount_paid' => 500]);

        $migration = require database_path('migrations/2026_10_11_200004_create_billing_transactions_table.php');
        (new ReflectionMethod($migration, 'carryInExistingBills'))->invoke($migration);

        $rows = BillingTransaction::query()->where('billing_id', $billing->id)->orderBy('id')->get();

        $this->assertSame(['opening_balance', 'payment', 'payment'], $rows->map(fn ($row): string => $row->type->value)->all());
        $this->assertSame('0.00', $rows->last()->balance_after);
        $this->assertTrue($this->drift()->isEmpty());

        $this->artisan('billing:verify-ledger')->assertSuccessful();
    }

    public function test_the_verify_command_reports_a_bill_that_does_not_add_up(): void
    {
        $request = $this->allocatedRequest(1);

        // Moved behind the journal's back, as a defect would.
        Billing::query()->where('request_id', $request->id)->update(['total_amount' => 1234]);

        $this->artisan('billing:verify-ledger')->assertFailed();
    }

    /**
     * File a payment correction as the clerk and approve it as the supervisor.
     *
     * @param  array<string, mixed>  $changes
     */
    private function approveCorrection(Payment $payment, array $changes): void
    {
        $id = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$payment->id}/corrections", ['changes' => $changes, 'reason' => 'Keyed wrongly.'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();
    }

    private function drift()
    {
        return app(BillingLedger::class)->drift(app(StatementFigures::class), $this->centre->id);
    }
}
