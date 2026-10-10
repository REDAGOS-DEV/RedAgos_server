<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Voiding a counter payment, on the Billing Supervisor's approval, with cash shifts on.
 *
 * What is locked in: a void is filed like a correction and decided by the
 * Billing Supervisor; it stays on the ledger, marked voided, with its receipt
 * voided and the bill re-settled; the journal reverses the payment's row in
 * the same shift; and it is only for a manual payment whose shift is still
 * open — anything later is a refund, settled outside RedAgos. Without shifts
 * the window is the day instead (CounterWithoutShiftsTest).
 */
class PaymentVoidTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    private BloodRequest $request;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['blood_center.cash_shifts' => true]);
        $this->setUpBillingFixtures();
        $this->priceComponent(900);
        $this->shiftFor($this->billingClerk, 100000);

        $this->request = $this->allocatedRequest(1);
        $this->payCash($this->request, 900, ['amount_tendered' => 1000])->assertCreated();
        $this->payment = Payment::query()->sole();
    }

    public function test_the_clerk_files_a_void_and_the_supervisor_applies_it(): void
    {
        $id = $this->fileVoid()
            ->assertCreated()
            ->assertJsonPath('data.subject', 'payment_void')
            ->assertJsonPath('data.changes.void', true)
            ->json('data.id');

        // Nothing changes until it is approved.
        $this->assertSame(PaymentStatus::Completed, $this->payment->refresh()->status);

        $this->actingAs($this->billingClerk)->postJson("/api/blood-center/corrections/{$id}/approve")->assertForbidden();
        $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->payment->refresh();
        $this->assertSame(PaymentStatus::Voided, $this->payment->status);
        $this->assertSame($this->billingSupervisor->id, $this->payment->voided_by);
        $this->assertSame('Charged to the wrong patient.', $this->payment->void_reason);

        $this->assertTrue(PaymentReceipt::query()->sole()->isVoided());
        $this->assertSame(BillingStatus::Unpaid, $this->request->billing()->first()->status);

        $void = BillingTransaction::query()->where('type', 'payment_void')->sole();
        $original = BillingTransaction::query()->where('type', 'payment')->sole();
        $this->assertSame('900.00', $void->amount);
        $this->assertSame('900.00', $void->balance_after);
        $this->assertSame($original->id, $void->reverses_transaction_id);
        $this->assertSame($original->cash_session_id, $void->cash_session_id);

        // The drawer gave the money back.
        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertJsonPath('session.figures.cash_collected', '900.00')
            ->assertJsonPath('session.figures.cash_voided', '900.00')
            ->assertJsonPath('session.figures.expected_cash', '1000.00')
            ->assertJsonPath('session.counts.voids', 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.payment_voided', 'actor_id' => $this->billingSupervisor->id]);
    }

    public function test_the_payments_list_offers_a_void_only_while_it_can_be_filed(): void
    {
        $uri = "/api/blood-center/billings/{$this->request->id}/payments";

        $this->actingAs($this->billingClerk)->getJson($uri)
            ->assertJsonPath('payments.0.can_request_void', true)
            ->assertJsonPath('payments.0.amount_tendered', '1000.00')
            ->assertJsonPath('payments.0.change_given', '100.00')
            ->assertJsonPath('payments.0.cash_session.is_open', true);

        $this->fileVoid()->assertCreated();

        $this->actingAs($this->billingClerk)->getJson($uri)
            ->assertJsonPath('payments.0.pending_void', true)
            ->assertJsonPath('payments.0.can_request_void', false)
            ->assertJsonPath('payments.0.can_request_correction', false);
    }

    public function test_a_void_and_a_correction_of_one_payment_never_wait_side_by_side(): void
    {
        $this->fileVoid()->assertCreated();

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$this->payment->id}/corrections", [
                'changes' => ['amount_paid' => 800, 'payment_method' => 'cash'],
                'reason' => 'Keyed wrongly.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'correction_pending');
    }

    public function test_a_shift_cannot_close_while_a_void_of_its_payment_awaits_a_decision(): void
    {
        $this->fileVoid()->assertCreated();
        $shiftId = $this->payment->cash_session_id;

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/pos/sessions/{$shiftId}/close", ['counted_cash' => 1900])
            ->assertStatus(409)
            ->assertJsonPath('code', 'void_pending_on_shift');
    }

    public function test_once_the_shift_has_closed_it_is_a_refund_not_a_void(): void
    {
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/pos/sessions/{$this->payment->cash_session_id}/close", ['counted_cash' => 1900])
            ->assertOk();

        $this->fileVoid()->assertStatus(409)->assertJsonPath('code', 'void_window_closed');
    }

    public function test_a_void_filed_while_the_shift_was_open_is_not_applied_after_it_closed(): void
    {
        $id = $this->fileVoid()->assertCreated()->json('data.id');

        // The supervisor rejects nothing, but the shift is closed from under it.
        CashSession::query()->whereKey($this->payment->cash_session_id)->update([
            'status' => 'closed', 'closed_at' => now(), 'expected_cash' => '1900.00', 'counted_cash' => '1900.00', 'variance' => '0.00',
        ]);

        $this->actingAs($this->billingSupervisor)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'void_window_closed');

        $this->assertSame(PaymentStatus::Completed, $this->payment->refresh()->status);
    }

    public function test_a_gateway_payment_is_never_voided(): void
    {
        $gateway = Payment::factory()->create([
            'billing_id' => $this->payment->billing_id,
            'amount_paid' => 100,
            'payment_method' => 'gcash',
            'reference_number' => 'pay_gw_1',
            'source' => PaymentSource::Gateway,
            'cash_session_id' => $this->payment->cash_session_id,
        ]);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$gateway->id}/void", ['reason' => 'Payer changed their mind.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'gateway_payment_not_voidable');
    }

    public function test_a_voided_payment_cannot_be_corrected_or_voided_again(): void
    {
        $id = $this->fileVoid()->assertCreated()->json('data.id');
        $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->fileVoid()->assertStatus(409)->assertJsonPath('code', 'payment_not_voidable');

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$this->payment->id}/corrections", [
                'changes' => ['amount_paid' => 800, 'payment_method' => 'cash'],
                'reason' => 'Keyed wrongly.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_voided');
    }

    public function test_a_void_needs_a_reason(): void
    {
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$this->payment->id}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    private function fileVoid()
    {
        return $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$this->payment->id}/void", ['reason' => 'Charged to the wrong patient.']);
    }
}
