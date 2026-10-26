<?php

namespace Tests\Feature\Billing;

use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Service\PaymentReceiptService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * One payment at the counter split across methods: part cash, the rest by
 * card, GCash or bank transfer (asked for by the project owner on 2026-10-11).
 *
 * What is locked in: every part is its own payment and journal row, and all
 * of them share one receipt that lists each part (project owner, 2026-10-15);
 * voiding or correcting a part reissues that receipt for the parts still
 * standing; the parts are recorded all or nothing, and a refusal names
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

    public function test_cash_and_a_card_settle_the_bill_on_one_receipt(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ], ['payer_name' => 'Ana Reyes'])
            ->assertCreated()
            ->assertJsonPath('billing.status', 'paid')
            ->assertJsonPath('billing.collected', 1800)
            ->assertJsonMissingPath('receipts')
            ->assertJsonPath('receipt.amount_paid', '1800.00')
            ->assertJsonPath('receipt.balance_before', '1800.00')
            ->assertJsonPath('receipt.balance_after', '0.00')
            ->assertJsonPath('receipt.is_partial', false)
            ->assertJsonPath('receipt.payment_method_label', 'Cash + Card')
            ->assertJsonPath('receipt.payment.amount_tendered', '1000.00')
            ->assertJsonPath('receipt.payer_name', 'Ana Reyes')
            ->assertJsonPath('receipt.tenders.0.method_label', 'Cash')
            ->assertJsonPath('receipt.tenders.0.amount_paid', '1000.00')
            ->assertJsonPath('receipt.tenders.1.method_label', 'Card')
            ->assertJsonPath('receipt.tenders.1.amount_paid', '800.00')
            // References go with the payments, not the receipt everyone reads.
            ->assertJsonMissingPath('receipt.tenders.1.reference_number')
            ->assertJsonPath('change_given', '0.00');

        $receipt = PaymentReceipt::query()->sole();
        $payments = Payment::query()->orderBy('id')->get();

        $this->assertCount(2, $payments);
        $this->assertSame($payments->pluck('id')->all(), $receipt->payments->pluck('id')->all());
        $this->assertSame($payments[0]->id, $receipt->payment_id);
        $this->assertSame([null, 'RRN-1'], array_column($receipt->snapshot['tenders'], 'reference_number'));

        // One statement paid, so both parts are pinned to the same revision of it.
        $this->assertSame(1, $payments->pluck('billing_revision_id')->unique()->count());

        $this->assertSame(
            [['-1000.00', 'cash', $receipt->id], ['-800.00', 'card', $receipt->id]],
            BillingTransaction::query()->where('type', 'payment')->orderBy('id')->get()
                ->map(fn (BillingTransaction $row): array => [$row->amount, $row->payment_method->value, $row->payment_receipt_id])->all()
        );

        // Each part, listed with its payment, names the receipt they share.
        $this->assertSame(
            [$receipt->receipt_number, $receipt->receipt_number],
            array_column(array_column(
                $this->actingAs($this->billingClerk)->getJson("/api/blood-center/billings/{$request->id}/payments")->json('payments'),
                'receipt'
            ), 'receipt_number')
        );
    }

    public function test_the_printed_receipt_lists_each_part(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1500],
            ['amount_paid' => 800, 'payment_method' => 'gcash', 'reference_number' => 'GC-77'],
        ])->assertCreated()->assertJsonPath('change_given', '500.00');

        $receipt = PaymentReceipt::query()->sole();
        $html = view('pdf.payment-receipt', app(PaymentReceiptService::class)->viewData($receipt))->render();

        $this->assertStringContainsString('Cash + GCash', $html);
        $this->assertStringContainsString('₱1,000.00', $html);
        $this->assertStringContainsString('₱800.00 &middot; Ref. GC-77', $html);
        $this->assertStringContainsString('₱1,800.00', $html);
        $this->assertStringContainsString('Cash tendered', $html);

        $this->actingAs($this->billingClerk)
            ->get("/api/blood-center/receipts/{$receipt->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Billing staff read each part's reference beside the receipt.
        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/receipts/{$receipt->id}")
            ->assertOk()
            ->assertJsonPath('payment_reference', null)
            ->assertJsonPath('payment_parts.0.method_label', 'Cash')
            ->assertJsonPath('payment_parts.0.reference_number', null)
            ->assertJsonPath('payment_parts.1.method_label', 'GCash')
            ->assertJsonPath('payment_parts.1.amount_paid', '800.00')
            ->assertJsonPath('payment_parts.1.reference_number', 'GC-77');
    }

    public function test_voiding_one_part_reissues_the_receipt_for_the_rest(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ], ['payer_name' => 'Ana Reyes'])->assertCreated();

        $original = PaymentReceipt::query()->sole();
        $cash = Payment::query()->where('payment_method', 'cash')->sole();
        $card = Payment::query()->where('payment_method', 'card')->sole();

        $id = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$card->id}/void", ['reason' => 'The card was declined after all.'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertTrue($original->refresh()->isVoided());

        $replacement = PaymentReceipt::query()->active()->sole();
        $this->assertSame($original->id, $replacement->replaces_receipt_id);
        $this->assertSame([$cash->id], $replacement->payments->pluck('id')->all());
        $this->assertSame('1800.00', $replacement->snapshot['balance_before']);
        $this->assertSame('1000.00', $replacement->snapshot['amount_paid']);
        $this->assertSame('800.00', $replacement->snapshot['balance_after']);
        $this->assertSame('Cash', $replacement->snapshot['payment']['method_label']);
        $this->assertSame('Ana Reyes', $replacement->snapshot['payer_name']);
        $this->assertSame($original->receipt_number, $replacement->snapshot['replaces_receipt_number']);
        $this->assertSame('partial', $request->billing()->first()->status->value);
    }

    /**
     * Billing's head needs no other approval for their own void (project
     * owner, 2026-10-15), so it is applied, and the shared receipt reissued,
     * as it is filed.
     */
    public function test_the_billing_supervisors_own_void_of_a_part_reissues_the_receipt_at_once(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ])->assertCreated();

        $original = PaymentReceipt::query()->sole();
        $cash = Payment::query()->where('payment_method', 'cash')->sole();
        $card = Payment::query()->where('payment_method', 'card')->sole();

        $this->actingAs($this->billingSupervisor)
            ->postJson("/api/blood-center/payments/{$card->id}/void", ['reason' => 'The card was declined after all.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        $this->assertTrue($original->refresh()->isVoided());
        $this->assertSame([$cash->id], PaymentReceipt::query()->active()->sole()->payments->pluck('id')->all());
        $this->assertSame('partial', $request->billing()->first()->status->value);
    }

    public function test_voiding_every_part_leaves_no_receipt_standing(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ])->assertCreated();

        foreach (Payment::query()->orderBy('id')->get() as $payment) {
            $id = $this->actingAs($this->billingClerk)
                ->postJson("/api/blood-center/payments/{$payment->id}/void", ['reason' => 'Recorded against the wrong bill.'])
                ->assertCreated()
                ->json('data.id');

            $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();
        }

        // The shared receipt, then the one reissued for the cash alone.
        $this->assertSame(2, PaymentReceipt::query()->count());
        $this->assertSame(0, PaymentReceipt::query()->active()->count());
    }

    public function test_correcting_one_part_reissues_the_whole_receipt(): void
    {
        $request = $this->allocatedRequest(2); // 1,800

        $this->split($request, [
            ['amount_paid' => 1000, 'payment_method' => 'cash', 'amount_tendered' => 1000],
            ['amount_paid' => 800, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
        ])->assertCreated();

        $original = PaymentReceipt::query()->sole();
        $card = Payment::query()->where('payment_method', 'card')->sole();

        $id = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$card->id}/corrections", [
                'reason' => 'Keyed in wrongly at the cashier.',
                'changes' => ['amount_paid' => 700, 'payment_method' => 'card', 'reference_number' => 'RRN-1'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertTrue($original->refresh()->isVoided());

        $replacement = PaymentReceipt::query()->active()->sole();
        $this->assertSame($original->id, $replacement->replaces_receipt_id);
        $this->assertSame(Payment::query()->orderBy('id')->pluck('id')->all(), $replacement->payments->pluck('id')->all());
        $this->assertSame('1700.00', $replacement->snapshot['amount_paid']);
        $this->assertSame('100.00', $replacement->snapshot['balance_after']);
        $this->assertSame(['1000.00', '700.00'], array_column($replacement->snapshot['tenders'], 'amount_paid'));
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
