<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingStatus;
use App\Enums\PaymentStatus;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\CashSession;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * The billing counter with cash shifts off, as it ships.
 *
 * The owner decided on 2026-10-11 that with one billing staff member there is
 * no drawer to hand over. What is locked in: a counter payment is taken
 * without a shift and carries none, on the payment and in the journal; no
 * shift can be opened; and a void is allowed on the operational (Manila) day
 * the payment was taken — after it, money handed back is a refund, settled
 * outside RedAgos.
 */
class CounterWithoutShiftsTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    private BloodRequest $request;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        // 07:30 in Manila is still the day before in UTC, so a void later the
        // same morning proves the window is the operational day, not UTC's.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 07:30', 'Asia/Manila'));

        $this->setUpBillingFixtures();
        $this->priceComponent(900);

        $this->request = $this->allocatedRequest(1);
        $this->payCash($this->request, 900, ['amount_tendered' => 1000])
            ->assertCreated()
            ->assertJsonPath('change_given', '100.00');
        $this->payment = Payment::query()->sole();
    }

    public function test_a_counter_payment_is_taken_without_a_shift(): void
    {
        $this->assertNull($this->payment->cash_session_id);
        $this->assertSame(PaymentStatus::Completed, $this->payment->status);
        $this->assertSame(BillingStatus::Paid, $this->request->billing()->first()->status);

        $this->assertNull(BillingTransaction::query()->where('type', 'payment')->sole()->cash_session_id);
        $this->assertSame(0, CashSession::query()->count());
    }

    public function test_the_counter_says_it_has_no_shifts_and_will_not_open_one(): void
    {
        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertOk()
            ->assertJsonPath('shifts_enabled', false)
            ->assertJsonPath('session', null);

        $this->actingAs($this->billingClerk)
            ->postJson('/api/blood-center/pos/sessions', ['opening_float' => 1000])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_shifts_disabled');

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/sessions')
            ->assertOk()
            ->assertJsonPath('shifts_enabled', false);

        $this->assertSame(0, CashSession::query()->count());
    }

    public function test_a_payment_is_voided_on_the_day_it_was_taken(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'Asia/Manila'));

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$this->request->id}/payments")
            ->assertJsonPath('payments.0.can_request_void', true)
            ->assertJsonPath('payments.0.cash_session', null);

        $id = $this->fileVoid()->assertCreated()->json('data.id');
        $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertSame(PaymentStatus::Voided, $this->payment->refresh()->status);
        $this->assertSame(BillingStatus::Unpaid, $this->request->billing()->first()->status);

        $void = BillingTransaction::query()->where('type', 'payment_void')->sole();
        $this->assertSame('900.00', $void->amount);
        $this->assertNull($void->cash_session_id);
        $this->assertSame(BillingTransaction::query()->where('type', 'payment')->value('id'), $void->reverses_transaction_id);
    }

    public function test_the_next_day_it_is_a_refund_not_a_void(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 00:05', 'Asia/Manila'));

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$this->request->id}/payments")
            ->assertJsonPath('payments.0.can_request_void', false);

        $this->fileVoid()
            ->assertStatus(409)
            ->assertJsonPath('code', 'void_window_closed')
            ->assertJsonPath('message', 'This payment was taken on an earlier day. Money handed back now is a refund, settled outside RedAgos.');
    }

    public function test_a_void_filed_on_the_day_is_not_applied_the_next(): void
    {
        $id = $this->fileVoid()->assertCreated()->json('data.id');

        $this->travelTo(CarbonImmutable::parse('2026-10-13 08:00', 'Asia/Manila'));

        $this->actingAs($this->billingSupervisor)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'void_window_closed');

        $this->assertSame(PaymentStatus::Completed, $this->payment->refresh()->status);
    }

    private function fileVoid()
    {
        return $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$this->payment->id}/void", ['reason' => 'Charged to the wrong patient.']);
    }
}
