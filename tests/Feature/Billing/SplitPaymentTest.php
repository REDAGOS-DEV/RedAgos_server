<?php

namespace Tests\Feature\Billing;

use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * One payment at the counter split across methods: part cash, the rest by
 * card, GCash or bank transfer (asked for by the project owner on 2026-10-11).
 *
 * What is locked in: every part is an ordinary payment with its own receipt
 * and journal row; the parts are recorded all or nothing, and a refusal names
 * the part it came from; each part is held to the balance the parts before it
 * left; the cash is one part at most, and only cash gives change; and the
 * drawer counts only the cash part.
 */
class SplitPaymentTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_cash_and_a_card_settle_the_bill_with_a_receipt_each(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $response = $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ], ['payer_name' => 'Ana Reyes'])
            ->assertCreated()
            ->assertJsonPath('billing.status', 'paid')
            ->assertJsonPath('billing.collected', 1800)
            ->assertJsonCount(2, 'receipts')
            ->assertJsonPath('receipts.0.amount_paid', '1000.00')
            ->assertJsonPath('receipts.0.payment_method_label', 'Cash')
            ->assertJsonPath('receipts.0.balance_after', '800.00')
            ->assertJsonPath('receipts.1.payment_method_label', 'Card')
            ->assertJsonPath('receipts.1.balance_after', '0.00')
            ->assertJsonPath('receipts.1.payer_name', 'Ana Reyes')
            ->assertJsonPath('change_given', '0.00');

        $this->assertNotSame($response->json('receipts.0.receipt_number'), $response->json('receipts.1.receipt_number'));
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(
            [['-1000.00', 'cash'], ['-800.00', 'card']],
            BillingTransaction::query()->where('type', 'payment')->orderBy('id')->get()
                ->map(fn (BillingTransaction $row): array => [$row->amount, $row->payment_method->value])->all()
        );
    }

    public function test_only_the_card_part_carries_additional_information(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000, 'additional_information' => 'Dela Cruz'],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ])->assertJsonValidationErrors(['tenders.0.additional_information' => 'Only a card payment carries additional information.']);

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1', 'additional_information' => 'Dela Cruz'],
        ])->assertCreated();

        $this->assertSame(
            [['cash', null], ['card', 'Dela Cruz']],
            Payment::query()->orderBy('id')->get()
                ->map(fn (Payment $payment): array => [$payment->payment_method->value, $payment->additional_information])->all()
        );
    }

    public function test_cash_paid_last_gives_the_change(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'gcash', 'reference_number' => 'GC-1'],
            ['amount_paid' => 800, 'payment_method' => 'cash', 'amount_tendered' => 1000],
        ])
            ->assertCreated()
            ->assertJsonPath('change_given', '200.00')
            ->assertJsonPath('message', 'Payment recorded in 2 parts. Change due: 200.00.');
    }

    public function test_a_refused_part_records_none_of_them(): void
    {
        $earlier = $this->allocatedRequest(1);
        $this->payCash($earlier, 900, ['payment_method' => 'card', 'reference_number' => 'RRN-USED'])->assertCreated();

        $request = $this->allocatedRequest(2);

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-USED'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenders.1.reference_number' => 'That payment reference has already been recorded.']);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentReceipt::query()->count());
    }

    public function test_each_part_is_held_to_what_the_parts_before_it_left(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 900, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenders.1.amount_paid' => 'Only 800.00 is outstanding. Record that much; anything handed over beyond it is change.']);

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_parts_follow_the_single_payment_rules(): void
    {
        $request = $this->allocatedRequest(2);

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'bank_transfer'],
        ])->assertJsonValidationErrors(['tenders.1.reference_number' => 'A GCash, card or bank-transfer payment needs its reference number.']);

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'card', 'reference_number' => 'RRN-1', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'cash'],
        ])->assertJsonValidationErrors('tenders.0.amount_tendered');

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'card', 'reference_number' => 'SAME'],
            ['amount_paid' => 800, 'payment_method' => 'gcash', 'reference_number' => 'SAME'],
        ])->assertJsonValidationErrors(['tenders.0.reference_number' => 'Each part needs its own reference.']);

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash'],
            ['amount_paid' => 800, 'payment_method' => 'cash'],
        ])->assertJsonValidationErrors(['tenders' => 'Count the cash once: a split payment has at most one cash part.']);

        $this->split($request, [
            ['amount_paid' => 1800, 'payment_method' => 'cash'],
        ])->assertJsonValidationErrors('tenders');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_drawer_counts_only_the_cash_part(): void
    {
        config(['blood_center.cash_shifts' => true]);
        $this->shiftFor($this->billingClerk, 50000);
        $request = $this->allocatedRequest(2);

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'bank_transfer', 'reference_number' => 'TRF-1'],
        ])->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertJsonPath('session.figures.cash_collected', '1000.00')
            ->assertJsonPath('session.figures.bank_transfer_counter', '800.00')
            ->assertJsonPath('session.figures.expected_cash', '1500.00')
            ->assertJsonPath('session.figures.total_collected', '1800.00');
    }

    public function test_only_billing_staff_split_a_payment(): void
    {
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/payments/split", [
                'tenders' => [
                    ['amount_paid' => 1000, 'payment_method' => 'cash'],
                    ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
                ],
            ])
            ->assertForbidden();
    }

    /**
     * @param  array<int, array<string, mixed>>  $tenders
     * @param  array<string, mixed>  $extra
     */
    private function split(BloodRequest $request, array $tenders, array $extra = []): TestResponse
    {
        return $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/payments/split", ['tenders' => $tenders, ...$extra]);
    }
}
