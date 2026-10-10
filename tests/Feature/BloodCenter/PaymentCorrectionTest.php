<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BillingStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\User;
use App\Service\BillingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Corrections to a recorded payment, and the lock discipline every billing writer shares.
 *
 * The Billing head is the Billing Supervisor. The Billing Clerk records
 * payments and files the correction; the supervisor decides it. A payment's
 * reference number is read only behind billing.record_payment, never on the
 * statement the release gate reads.
 */
class PaymentCorrectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private Facility $hospital;

    private User $supervisor;

    private User $clerk;

    private User $issuance;

    private User $centerAdmin;

    private User $requester;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    private BloodRequest $request;

    private Billing $billing;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->hospital = Facility::factory()->bloodBank()->approved()->create();

        $this->supervisor = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingSupervisor)->create();
        $this->clerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();
        $this->issuance = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();
        $this->centerAdmin = User::factory()->bloodCenterSupervisor($this->centre)->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC', 'price' => 0]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);

        $this->request = $this->requestForStock();
        $this->billing = Billing::factory()->paid(1500)->create(['request_id' => $this->request->id]);
        $this->payment = Payment::factory()->create(['billing_id' => $this->billing->id, 'amount_paid' => 1500]);
    }

    public function test_the_clerk_files_and_the_supervisor_applies_a_payment_correction(): void
    {
        $id = $this->file($this->clerk, $this->payment, $this->change(amount: 1000))
            ->assertCreated()
            ->assertJsonPath('data.subject', 'payment')
            ->assertJsonPath('data.approver_label', StaffRole::BillingSupervisor->label())
            ->json('data.id');

        $this->assertDatabaseHas('correction_requests', ['id' => $id, 'payment_id' => $this->payment->id, 'donation_id' => null]);
        $this->assertSame('1500.00', $this->payment->refresh()->amount_paid);

        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertSame('1000.00', $this->payment->refresh()->amount_paid);
        $this->assertSame(BillingStatus::Partial, $this->billing->refresh()->status);

        $audit = AuditLog::query()->where('action', 'billing.payment_corrected')->firstOrFail();
        $this->assertSame('paid', $audit->context['status_from']);
        $this->assertSame('partial', $audit->context['status_to']);
        $this->assertSame(['amount_paid'], $audit->context['fields']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'correction.applied', 'auditable_id' => (string) $this->payment->id]);
    }

    public function test_a_clerk_cannot_approve_and_a_supervisors_own_request_goes_to_the_center_admin(): void
    {
        $id = $this->file($this->clerk, $this->payment, $this->change(amount: 1000))->assertCreated()->json('data.id');

        $this->actingAs($this->clerk)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertForbidden();

        $own = $this->file($this->supervisor, $this->payment, $this->change(amount: 900));
        $own->assertStatus(409)->assertJsonPath('code', 'correction_pending');

        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$id}/reject", ['note' => 'Not needed.'])->assertOk();

        $mine = $this->file($this->supervisor, $this->payment, $this->change(amount: 900))->assertCreated()->json('data.id');

        $peer = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingSupervisor)->create();

        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$mine}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'self_approval');
        $this->actingAs($peer)->postJson("/api/blood-center/corrections/{$mine}/approve")
            ->assertForbidden()->assertJsonPath('code', 'not_the_approver');
        $this->actingAs($this->centerAdmin)->postJson("/api/blood-center/corrections/{$mine}/approve")->assertOk();

        $this->assertSame('900.00', $this->payment->refresh()->amount_paid);
    }

    public function test_the_filing_policy_runs_before_the_payment_is_looked_up(): void
    {
        $custom = User::factory()->bloodCenterCustomStaff($this->centre, Department::Billing, 'Cashier')->create();

        // A custom billing role records payments and may file corrections by
        // ability, but the subject is a named post's. Refused whether or not
        // the payment exists.
        foreach ([$this->payment->id, 999999] as $paymentId) {
            $this->actingAs($custom)
                ->postJson("/api/blood-center/payments/{$paymentId}/corrections", ['reason' => 'x', 'changes' => $this->change(amount: 1000)])
                ->assertForbidden()
                ->assertJsonPath('code', 'not_your_record');
        }

        $this->assertDatabaseCount('correction_requests', 0);
    }

    public function test_other_roles_may_not_file_a_payment_correction(): void
    {
        foreach ([$this->issuance, User::factory()->bloodCenterStaff($this->centre, StaffRole::ItDataClerk)->create()] as $user) {
            $this->file($user, $this->payment, $this->change(amount: 1000))->assertForbidden();
        }
    }

    public function test_the_correction_is_checked_with_the_original_payment_rules(): void
    {
        $other = Payment::factory()->gcash('GC-TAKEN')->create(['billing_id' => $this->billing->id]);

        // A reference another payment holds.
        $this->file($this->clerk, $this->payment, $this->change(method: 'gcash', reference: 'GC-TAKEN'))
            ->assertStatus(422)->assertJsonValidationErrors('changes.reference_number');

        // GCash needs its reference.
        $this->file($this->clerk, $this->payment, $this->change(method: 'gcash', reference: null))
            ->assertStatus(422)->assertJsonValidationErrors('changes.reference_number');

        // A correction cannot turn a payment into a refused one.
        $this->file($this->clerk, $this->payment, [...$this->change(amount: 1000), 'status' => 'refunded'])
            ->assertStatus(422)->assertJsonValidationErrors('changes.status');

        // Keeping its own reference is not a duplicate of itself.
        $this->file($this->clerk, $other, $this->change(amount: 1200, method: 'gcash', reference: 'GC-TAKEN'))->assertCreated();
    }

    public function test_a_voided_statement_refuses_a_correction_and_a_subsidised_one_keeps_its_status(): void
    {
        $this->billing->update(['status' => BillingStatus::Void]);

        $this->file($this->clerk, $this->payment, $this->change(amount: 1000))
            ->assertStatus(409)->assertJsonPath('code', 'billing_void');

        $this->billing->update(['status' => BillingStatus::Subsidised]);

        $id = $this->file($this->clerk, $this->payment, $this->change(amount: 1000))->assertCreated()->json('data.id');
        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertSame('1000.00', $this->payment->refresh()->amount_paid);
        $this->assertSame(BillingStatus::Subsidised, $this->billing->refresh()->status, 'A decision is not overridden by a payment.');
    }

    public function test_another_facilitys_payment_is_not_found(): void
    {
        $stranger = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::BillingClerk)->create();

        $this->file($stranger, $this->payment, $this->change(amount: 1000))
            ->assertNotFound()
            ->assertJsonPath('code', 'payment_not_found');
    }

    public function test_an_amount_that_only_prints_differently_is_not_a_change_but_a_different_one_is(): void
    {
        $id = $this->file($this->clerk, $this->payment, $this->change(amount: 1000))->assertCreated()->json('data.id');

        // The same number, stored in another form, is still the record it was.
        Payment::query()->whereKey($this->payment->id)->update(['amount_paid' => 1500]);
        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $second = Payment::factory()->create(['billing_id' => $this->billing->id, 'amount_paid' => 200]);
        $stale = $this->file($this->clerk, $second, $this->change(amount: 250))->assertCreated()->json('data.id');

        Payment::query()->whereKey($second->id)->update(['amount_paid' => 999]);

        $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/corrections/{$stale}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'record_changed');

        $this->assertSame('pending', CorrectionRequest::findOrFail($stale)->status);
    }

    // --- Who may read the payments --------------------------------------------------

    public function test_the_payments_are_read_only_behind_the_ability_to_record_them(): void
    {
        $this->startWithoutPayments();
        $gcash = Payment::factory()->gcash('GC-SECRET-77')->create(['billing_id' => $this->billing->id]);

        $this->actingAs($this->clerk)
            ->getJson("/api/blood-center/billings/{$this->request->id}/payments")
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.id', $gcash->id)
            ->assertJsonPath('payments.0.reference_number', 'GC-SECRET-77')
            ->assertJsonPath('payments.0.can_request_correction', true)
            ->assertJsonPath('payments.0.pending_correction', false);

        // billing.view lets these roles read whether a release is cleared,
        // and nothing about the money behind it.
        foreach ([StaffRole::InventoryControlOfficer, StaffRole::DispatchCoordinator, StaffRole::ItDataClerk] as $role) {
            $this->actingAs(User::factory()->bloodCenterStaff($this->centre, $role)->create())
                ->getJson("/api/blood-center/billings/{$this->request->id}/payments")
                ->assertForbidden();
        }

        foreach (["/api/blood-center/billings/{$this->request->id}", '/api/blood-center/billings'] as $uri) {
            $body = $this->actingAs($this->issuance)->getJson($uri)->assertOk()->json();

            // Not the request's own reference, which the statement shows: the
            // payments, their amounts, methods and provider references.
            foreach (['"payments"', '"payment_method"', '"amount_paid"', 'GC-SECRET-77'] as $payment) {
                $this->assertStringNotContainsString($payment, json_encode($body));
            }
        }
    }

    public function test_what_the_viewer_may_do_with_each_payment_is_worked_out_per_viewer(): void
    {
        $uri = "/api/blood-center/billings/{$this->request->id}/payments";
        $custom = User::factory()->bloodCenterCustomStaff($this->centre, Department::Billing, 'Cashier')->create();

        $this->actingAs($this->supervisor)->getJson($uri)->assertOk()->assertJsonPath('payments.0.can_request_correction', true);

        // Reaches the list through the ability, but the subject is not theirs.
        $this->actingAs($custom)->getJson($uri)->assertOk()->assertJsonPath('payments.0.can_request_correction', false);

        $this->file($this->clerk, $this->payment, $this->change(amount: 1000))->assertCreated();

        $this->actingAs($this->clerk)->getJson($uri)->assertOk()
            ->assertJsonPath('payments.0.pending_correction', true)
            ->assertJsonPath('payments.0.can_request_correction', false);
    }

    // --- The billing lock contract --------------------------------------------------

    public function test_a_payment_is_settled_from_the_locked_statement_not_one_loaded_earlier(): void
    {
        $this->startWithoutPayments();
        $this->billing->update(['total_amount' => 1500, 'status' => BillingStatus::Unpaid]);

        $stale = Billing::findOrFail($this->billing->id);

        // The statement is re-priced after the caller loaded it.
        Billing::query()->whereKey($this->billing->id)->update(['total_amount' => 500]);

        app(BillingService::class)->recordPayment($this->clerk, $stale, ['amount_paid' => 500, 'payment_method' => 'cash']);

        $this->assertSame(BillingStatus::Paid, $this->billing->refresh()->status, 'Settled against 500, not the stale 1500.');
    }

    public function test_a_subsidy_is_judged_against_the_locked_statement(): void
    {
        $this->billing->update(['status' => BillingStatus::Unpaid]);
        $stale = Billing::findOrFail($this->billing->id);

        Billing::query()->whereKey($this->billing->id)->update(['status' => BillingStatus::Subsidised->value]);

        try {
            app(BillingService::class)->applySubsidy($this->centerAdmin, $stale);
            $this->fail('A statement already subsidised must refuse a second subsidy.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('billing_already_subsidised', $exception->getResponse()->getData(true)['code']);
        }
    }

    // --- Release and correction ----------------------------------------------------

    public function test_a_correction_approved_before_release_blocks_it_at_the_gate(): void
    {
        [$request, $billing, $payment] = $this->pricedRequestHeld(900);

        $this->approveAmount($payment, 400);

        $this->assertSame(BillingStatus::Partial, $billing->refresh()->status);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_unsettled');

        $this->assertSame(1, BloodUnit::query()->where('status', BloodUnitStatus::Reserved)->count());
    }

    /**
     * A true race between two transactions cannot be reproduced on a
     * single-connection SQLite test. What it rests on is that release() and a
     * correction both take the request lock first (see the locking note on
     * BillingService), so one waits for the other; this pins the two outcomes
     * that ordering allows.
     */
    public function test_a_correction_approved_after_release_reopens_the_balance_and_is_audited(): void
    {
        [$request, $billing, $payment] = $this->pricedRequestHeld(900);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $released = $request->refresh()->status;

        $this->approveAmount($payment, 400);

        // The units are out: the request does not go back.
        $this->assertSame($released, $request->refresh()->status);
        $this->assertSame(BloodRequestStatus::Fulfilled, $request->status);
        $this->assertSame(BillingStatus::Partial, $billing->refresh()->status);

        // Surfaced as outstanding rather than undone.
        $this->actingAs($this->clerk)
            ->getJson('/api/blood-center/billings?outstanding=1')
            ->assertOk()
            ->assertJsonPath('data.0.request_id', $request->id);

        $audit = AuditLog::query()->where('action', 'billing.payment_corrected')->firstOrFail();
        $this->assertSame('paid', $audit->context['status_from']);
        $this->assertSame('partial', $audit->context['status_to']);
        $this->assertSame(BloodRequestStatus::Fulfilled->value, $audit->context['request_status']);
    }

    // --- Helpers ------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $changes
     */
    private function file(User $as, Payment $payment, array $changes): TestResponse
    {
        return $this->actingAs($as)->postJson("/api/blood-center/payments/{$payment->id}/corrections", [
            'reason' => 'Keyed in wrongly at the cashier.',
            'changes' => $changes,
        ]);
    }

    /**
     * The whole corrected payload, as the original rules require it.
     *
     * @return array<string, mixed>
     */
    private function change(float|int $amount = 1500, string $method = 'cash', ?string $reference = null): array
    {
        return ['amount_paid' => $amount, 'payment_method' => $method, 'reference_number' => $reference];
    }

    private function approveAmount(Payment $payment, float|int $amount): void
    {
        $id = $this->file($this->clerk, $payment, $this->change(amount: $amount))->assertCreated()->json('data.id');

        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();
    }

    /**
     * A request with one unit held for it and a priced, paid statement.
     *
     * @return array{0: BloodRequest, 1: Billing, 2: Payment}
     */
    private function pricedRequestHeld(float $price): array
    {
        $request = $this->requestForStock();

        $donation = Donation::factory()->create(['facility_id' => $this->centre->id, 'donor_id' => $this->donorProfile->donor_id]);
        BloodUnit::factory()->create([
            'facility_id' => $this->centre->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
        ]);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => 1])
            ->assertOk();

        $billing = $request->billing()->firstOrFail();
        $billing->update(['total_amount' => $price, 'status' => BillingStatus::Paid]);

        $payment = Payment::factory()->create(['billing_id' => $billing->id, 'amount_paid' => $price]);

        return [$request->fresh(), $billing, $payment];
    }

    private function requestForStock(): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, 1)
            ->create();
    }

    /**
     * Point the test at a request and statement of its own, with no payment on it yet.
     *
     * A payment is never deleted (add_ledger_triggers_to_payments_table), so a
     * test that needs a statement without setUp's payment takes a fresh one
     * rather than removing that payment.
     */
    private function startWithoutPayments(): void
    {
        $this->request = $this->requestForStock();
        $this->billing = Billing::factory()->paid(1500)->create(['request_id' => $this->request->id]);
    }
}
