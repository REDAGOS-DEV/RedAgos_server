<?php

namespace Tests\Feature\Billing;

use App\Models\BillingTransaction;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Card payments and bank transfers at the counter, added by the project owner
 * on 2026-10-11.
 *
 * What is locked in: both are recorded from their reference, as a GCash
 * transfer is, and refused without one; neither takes an amount tendered; the
 * receipt and the journal name the method; the journal filters by it; a
 * payment can be corrected onto either; a shift shows both beside the drawer,
 * never in it; and the columns widened for them still refuse a deleted
 * payment.
 */
class CardAndBankTransferTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_a_card_payment_is_recorded_from_its_terminal_reference(): void
    {
        $request = $this->allocatedRequest(1);

        $this->payCash($request, 900, ['payment_method' => 'card', 'reference_number' => 'RRN-000123456789'])
            ->assertCreated()
            ->assertJsonPath('receipt.payment_method_label', 'Card');

        $payment = Payment::query()->sole();
        $this->assertSame('card', $payment->payment_method->value);
        $this->assertSame('RRN-000123456789', $payment->reference_number);
        $this->assertNull($payment->amount_tendered);

        $row = BillingTransaction::query()->where('type', 'payment')->sole();
        $this->assertSame('card', $row->payment_method->value);
        $this->assertSame('counter', $row->channel->value);
    }

    public function test_a_bank_transfer_needs_its_reference(): void
    {
        $request = $this->allocatedRequest(1);

        $this->payCash($request, 900, ['payment_method' => 'bank_transfer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_number' => 'A GCash, card or bank-transfer payment needs its reference number.']);

        $this->payCash($request, 900, ['payment_method' => 'bank_transfer', 'reference_number' => 'BDO-TRF-55120'])
            ->assertCreated()
            ->assertJsonPath('receipt.payment_method_label', 'Bank transfer');
    }

    public function test_only_cash_takes_an_amount_tendered(): void
    {
        $request = $this->allocatedRequest(1);

        $this->payCash($request, 900, ['payment_method' => 'card', 'reference_number' => 'RRN-1', 'amount_tendered' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_tendered');
    }

    public function test_the_journal_filters_by_the_new_methods(): void
    {
        $this->payCash($this->allocatedRequest(1), 900, ['payment_method' => 'card', 'reference_number' => 'RRN-1'])->assertCreated();
        $this->payCash($this->allocatedRequest(1), 900, ['payment_method' => 'bank_transfer', 'reference_number' => 'TRF-1'])->assertCreated();
        $this->payCash($this->allocatedRequest(1), 900, ['amount_tendered' => 900])->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billing-transactions?payment_method=bank_transfer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_method', 'bank_transfer')
            ->assertJsonPath('data.0.payment_method_label', 'Bank transfer');

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billing-transactions?payment_method=cheque')
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_a_payment_can_be_corrected_onto_a_bank_transfer_with_its_reference(): void
    {
        $request = $this->allocatedRequest(1);
        $this->payCash($request, 900, ['amount_tendered' => 900])->assertCreated();
        $payment = Payment::query()->sole();

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$payment->id}/corrections", [
                'changes' => ['amount_paid' => 900, 'payment_method' => 'bank_transfer', 'reference_number' => null],
                'reason' => 'Paid by transfer, keyed as cash.',
            ])
            ->assertUnprocessable();

        $id = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$payment->id}/corrections", [
                'changes' => ['amount_paid' => 900, 'payment_method' => 'bank_transfer', 'reference_number' => 'TRF-FIX-1'],
                'reason' => 'Paid by transfer, keyed as cash.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->billingSupervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertSame('bank_transfer', $payment->fresh()->payment_method->value);

        // Taken out of cash and put back under the transfer.
        $moved = BillingTransaction::query()->where('type', 'payment_correction')->orderBy('id')->get();
        $this->assertSame(
            [['900.00', 'cash'], ['-900.00', 'bank_transfer']],
            $moved->map(fn (BillingTransaction $row): array => [$row->amount, $row->payment_method->value])->all()
        );
    }

    public function test_a_shift_shows_card_and_bank_transfers_beside_the_drawer(): void
    {
        config(['blood_center.cash_shifts' => true]);
        $this->shiftFor($this->billingClerk, 50000);

        $this->payCash($this->allocatedRequest(1), 900, ['payment_method' => 'card', 'reference_number' => 'RRN-1'])->assertCreated();
        $this->payCash($this->allocatedRequest(1), 400, ['payment_method' => 'bank_transfer', 'reference_number' => 'TRF-1'])->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertJsonPath('session.figures.cash_collected', '0.00')
            ->assertJsonPath('session.figures.card_counter', '900.00')
            ->assertJsonPath('session.figures.bank_transfer_counter', '400.00')
            ->assertJsonPath('session.figures.expected_cash', '500.00')
            ->assertJsonPath('session.figures.total_collected', '1300.00');
    }

    public function test_a_card_payment_still_cannot_be_deleted(): void
    {
        $this->payCash($this->allocatedRequest(1), 900, ['payment_method' => 'card', 'reference_number' => 'RRN-1'])->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('payments')->delete();
    }
}
