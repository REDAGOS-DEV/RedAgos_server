<?php

namespace Tests\Feature\BloodRequest;

use App\Models\Facility;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Payment Acknowledgement Receipts: one per confirmed payment, frozen at issue.
 *
 * Issued with the payment, numbered per centre, carrying the balance before
 * and after so a partial payment never reads as settling the statement. The
 * database allows exactly one change to a receipt — a complete void, once.
 */
class PaymentReceiptTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(500);
    }

    private function assertRefusedByDatabase(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database should have refused that write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function receipt(): PaymentReceipt
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 400)->assertCreated();

        return PaymentReceipt::query()->sole();
    }

    public function test_a_part_payment_gets_a_receipt_showing_the_balance_before_and_after(): void
    {
        $request = $this->allocatedRequest(2);

        $this->payCash($request, 400, ['payer_name' => 'Juan dela Cruz'])
            ->assertCreated()
            ->assertJsonPath('receipt.receipt_number', "AR-{$this->centre->id}-000001")
            ->assertJsonPath('receipt.amount_paid', '400.00')
            ->assertJsonPath('receipt.balance_after', '600.00')
            ->assertJsonPath('receipt.is_partial', true);

        $snapshot = PaymentReceipt::query()->sole()->snapshot;

        $this->assertSame('1000.00', $snapshot['balance_before']);
        $this->assertSame('Juan dela Cruz', $snapshot['payer_name']);
        $this->assertSame($request->reference_number, $snapshot['request']['reference_number']);
        $this->assertNotNull($snapshot['statement']['document_number']);
        $this->assertSame('manual', $snapshot['payment']['source']);
    }

    public function test_a_receipt_carries_what_the_payment_was_for_and_shows_it_without_the_reference(): void
    {
        $request = $this->allocatedRequest(2);

        $response = $this->payCash($request, 1000, ['payment_method' => 'gcash', 'reference_number' => 'GC-REF-77', 'payer_name' => 'Ana Reyes'])
            ->assertCreated()
            ->assertJsonPath('receipt.balance_before', '1000.00')
            ->assertJsonPath('receipt.payer_name', 'Ana Reyes')
            ->assertJsonPath('receipt.issuing_facility.name', $this->centre->name)
            ->assertJsonPath('receipt.request.reference_number', $request->reference_number)
            ->assertJsonPath('receipt.payment.method_label', 'GCash')
            ->assertJsonCount(1, 'receipt.lines')
            ->assertJsonPath('receipt.lines.0.quantity', 2)
            ->assertJsonPath('receipt.lines.0.line_total', '1000.00');

        $this->assertStringNotContainsString('GC-REF-77', json_encode($response->json('receipt')));
        $this->assertSame('500.00', PaymentReceipt::query()->sole()->snapshot['lines'][0]['unit_price']);
    }

    public function test_the_payment_that_settles_the_statement_is_not_marked_partial(): void
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 400)->assertCreated();

        $this->payCash($request, 600)
            ->assertCreated()
            ->assertJsonPath('receipt.receipt_number', "AR-{$this->centre->id}-000002")
            ->assertJsonPath('receipt.balance_after', '0.00')
            ->assertJsonPath('receipt.is_partial', false);
    }

    public function test_a_later_top_up_leaves_a_receipt_already_issued_as_it_was(): void
    {
        $request = $this->allocatedRequest(1, askedFor: 3);
        $this->payCash($request, 500)->assertCreated();
        $before = PaymentReceipt::query()->sole()->snapshot;

        $this->topUp($request, 2);

        $this->assertSame($before, PaymentReceipt::query()->sole()->snapshot);
    }

    public function test_the_receipt_downloads_behind_the_ability_to_record_payments(): void
    {
        $receipt = $this->receipt();

        $this->actingAs($this->billingClerk)
            ->get("/api/blood-center/receipts/{$receipt->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // billing.view only: reads whether release is cleared, not the money.
        $this->actingAs($this->inventoryStaff)
            ->getJson("/api/blood-center/receipts/{$receipt->id}/pdf")
            ->assertForbidden();

        $this->actingAs($this->requester)
            ->get("/api/hospital/receipts/{$receipt->id}/pdf")
            ->assertOk();

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $this->actingAs(User::factory()->bloodBankStaff($otherHospital)->create())
            ->getJson("/api/hospital/receipts/{$receipt->id}/pdf")
            ->assertNotFound()
            ->assertJsonPath('code', 'receipt_not_found');
    }

    public function test_an_approved_correction_voids_the_receipt_and_issues_its_replacement(): void
    {
        $original = $this->receipt();
        $payment = Payment::query()->sole();

        $correctionId = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$payment->id}/corrections", [
                'reason' => 'Keyed in wrongly at the counter.',
                'changes' => ['amount_paid' => 450, 'payment_method' => 'cash', 'reference_number' => null],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->billingSupervisor)
            ->postJson("/api/blood-center/corrections/{$correctionId}/approve")
            ->assertOk();

        $original->refresh();
        $this->assertTrue($original->isVoided());
        $this->assertNotNull($original->voided_by);
        $this->assertStringContainsString('correction', $original->void_reason);

        $replacement = PaymentReceipt::query()->whereNull('voided_at')->sole();

        $this->assertSame($original->id, $replacement->replaces_receipt_id);
        $this->assertSame('450.00', $replacement->snapshot['amount_paid']);
        $this->assertSame('1000.00', $replacement->snapshot['balance_before'], 'The balance the payment was made against stays.');
        $this->assertSame('550.00', $replacement->snapshot['balance_after']);
        $this->assertSame($original->receipt_number, $replacement->snapshot['replaces_receipt_number']);
    }

    public function test_the_database_refuses_any_change_to_a_receipt_but_one_complete_void(): void
    {
        $receipt = $this->receipt();
        $id = $receipt->id;

        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->update(['snapshot' => '{}']));
        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->update(['receipt_number' => 'AR-X']));
        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->delete());

        // A partial void: a time with nobody and no reason.
        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->update(['voided_at' => now()]));

        // An empty reason is no reason.
        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->update([
            'voided_at' => now(), 'voided_by' => $this->billingSupervisor->id, 'void_reason' => '   ',
        ]));

        // Voiding while changing anything else.
        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->update([
            'voided_at' => now(), 'voided_by' => $this->billingSupervisor->id, 'void_reason' => 'Wrong.', 'receipt_number' => 'AR-Y',
        ]));

        // The one change allowed.
        $voided = DB::table('payment_receipts')->where('id', $id)->update([
            'voided_at' => now(), 'voided_by' => $this->billingSupervisor->id, 'void_reason' => 'Issued in error.',
        ]);
        $this->assertSame(1, $voided);

        // And only once: no second void, no editing the reason, no unvoiding.
        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->update(['void_reason' => 'Changed my mind.']));
        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->where('id', $id)->update([
            'voided_at' => null, 'voided_by' => null, 'void_reason' => null,
        ]));
    }

    public function test_the_model_refuses_to_change_a_receipt_except_to_void_it(): void
    {
        $receipt = $this->receipt();

        $this->expectException(LogicException::class);

        $receipt->update(['snapshot' => ['amount_paid' => '1.00']]);
    }

    public function test_a_payment_has_at_most_one_active_receipt(): void
    {
        $receipt = $this->receipt();

        $this->assertRefusedByDatabase(fn () => DB::table('payment_receipts')->insert([
            'payment_id' => $receipt->payment_id,
            'issuing_facility_id' => $this->centre->id,
            'receipt_number' => 'AR-DUPLICATE',
            'issued_at' => now(),
            'snapshot' => '{}',
            'created_at' => now(),
        ]));
    }

    public function test_a_subsidy_issues_no_receipt(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk();

        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    public function test_the_payments_list_shows_each_payments_source_and_receipt(): void
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 400)->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}/payments")
            ->assertOk()
            ->assertJsonPath('payments.0.source', 'manual')
            ->assertJsonPath('payments.0.receipt.receipt_number', "AR-{$this->centre->id}-000001");
    }
}
