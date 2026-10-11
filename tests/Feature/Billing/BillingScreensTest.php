<?php

namespace Tests\Feature\Billing;

use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * What the billing screens read: a statement's own page, the receipts list,
 * and the counter's overview.
 *
 * What is locked in: a statement is served with its request and its lines,
 * each with a blood type; receipts are listed for the issuing centre only,
 * linked back to their request and searchable by reference; billing staff see
 * a receipt's payment reference beside it; and the summary counts this
 * month's patient bills by status and today's payments.
 */
class BillingScreensTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_a_statement_is_served_with_its_request_and_lines(): void
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 500, ['amount_tendered' => 500])->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('billing.request.reference_number', $request->reference_number)
            ->assertJsonPath('billing.request.purpose_label', 'Patient Transfusion')
            ->assertJsonPath('billing.request.urgency_label', 'Routine')
            ->assertJsonPath('billing.lines.0.component_name', 'Packed Red Blood Cells')
            ->assertJsonPath('billing.lines.0.blood_type', 'O+')
            ->assertJsonPath('billing.lines.0.quantity', 2)
            ->assertJsonPath('billing.lines.0.line_total', '1800.00')
            ->assertJsonPath('billing.outstanding', '1300.00')
            ->assertJsonPath('billing.status', 'partial');
    }

    public function test_receipts_are_listed_for_the_issuing_centre_and_link_back(): void
    {
        $first = $this->allocatedRequest(1);
        $this->payCash($first, 900, ['amount_tendered' => 1000])->assertCreated();

        $second = $this->allocatedRequest(1);
        $this->payCash($second, 400, ['amount_tendered' => 400])->assertCreated();

        $response = $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/receipts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Newest first, each linked to the request and payment it came from.
        $this->assertSame($second->id, $response->json('data.0.request_id'));
        $this->assertSame('400.00', $response->json('data.0.amount_paid'));
        $this->assertTrue($response->json('data.0.is_partial'));
        $this->assertSame(Payment::query()->where('amount_paid', 400)->value('id'), $response->json('data.0.payment_id'));

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/receipts?search='.$first->reference_number)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_id', $first->id);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/receipts?status=voided')
            ->assertJsonCount(0, 'data');

        // A reader without the right to record payments never sees them.
        $this->actingAs($this->inventoryStaff)->getJson('/api/blood-center/receipts')->assertForbidden();
    }

    public function test_another_centres_receipts_are_not_listed(): void
    {
        $request = $this->allocatedRequest(1);
        $this->payCash($request, 900, ['amount_tendered' => 900])->assertCreated();

        $elsewhere = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::BillingClerk)->create();

        $this->actingAs($elsewhere)
            ->getJson('/api/blood-center/receipts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_billing_staff_read_a_receipts_payment_reference(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => 900,
                'payment_method' => 'gcash',
                'reference_number' => 'GC-778899',
            ])
            ->assertCreated();

        $receipt = PaymentReceipt::query()->sole();

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/receipts/{$receipt->id}")
            ->assertOk()
            ->assertJsonPath('payment_reference', 'GC-778899')
            ->assertJsonPath('receipt.request_id', $request->id)
            ->assertJsonMissingPath('receipt.payment.reference_number');
    }

    public function test_the_summary_counts_this_months_patient_bills_and_todays_payments(): void
    {
        $paid = $this->allocatedRequest(1);
        $this->payCash($paid, 900, ['amount_tendered' => 900])->assertCreated();

        $partial = $this->allocatedRequest(1);
        $this->payCash($partial, 100, ['amount_tendered' => 100])->assertCreated();

        $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billings/summary')
            ->assertOk()
            ->assertJsonPath('patient_bills_this_month.paid', 1)
            ->assertJsonPath('patient_bills_this_month.partial', 1)
            ->assertJsonPath('patient_bills_this_month.unpaid', 1)
            ->assertJsonPath('patient_bills_this_month.subsidised', 0)
            ->assertJsonPath('payments_today', 2);
    }

    public function test_journal_rows_name_the_patient(): void
    {
        $request = $this->allocatedRequest(1);
        $this->payCash($request, 900, ['amount_tendered' => 900])->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/billing-transactions?type=payment')
            ->assertOk()
            ->assertJsonPath('data.0.request.patient_name', $request->patientFullName())
            ->assertJsonPath('data.0.request.requesting_facility', $this->hospital->name);
    }
}
