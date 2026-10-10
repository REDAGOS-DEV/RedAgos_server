<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Enums\StaffRole;
use App\Models\CashSession;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * The billing counter's cash shifts.
 *
 * What is locked in: no counter payment without an open shift, and one open
 * shift per cashier; cash records the amount tendered and the change, and
 * never more than is owed; the drawer's expected cash is the float plus cash
 * taken; a difference at close must be explained; a closed shift never
 * changes; only the cashier or a billing supervisor closes it.
 *
 * All with shifts switched on (blood_center.cash_shifts); the counter without
 * them is CounterWithoutShiftsTest.
 */
class CashShiftTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['blood_center.cash_shifts' => true]);
        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_a_cashier_opens_one_shift_at_a_time(): void
    {
        $this->actingAs($this->billingClerk)
            ->postJson('/api/blood-center/pos/sessions', ['opening_float' => 1000, 'counter_label' => 'Counter 1'])
            ->assertCreated()
            ->assertJsonPath('session.session_number', "CS-{$this->centre->id}-000001")
            ->assertJsonPath('session.status', 'open')
            ->assertJsonPath('session.counter_label', 'Counter 1')
            ->assertJsonPath('session.figures.opening_float', '1000.00')
            ->assertJsonPath('session.figures.expected_cash', '1000.00');

        $this->actingAs($this->billingClerk)
            ->postJson('/api/blood-center/pos/sessions', ['opening_float' => 0])
            ->assertStatus(409)
            ->assertJsonPath('code', 'shift_already_open');

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertOk()
            ->assertJsonPath('session.session_number', "CS-{$this->centre->id}-000001");
    }

    public function test_the_database_holds_one_open_shift_per_cashier(): void
    {
        $this->shiftFor($this->billingClerk);

        $this->expectException(QueryException::class);

        DB::table('cash_sessions')->insert([
            'session_number' => 'CS-X-1',
            'facility_id' => $this->centre->id,
            'cashier_id' => $this->billingClerk->id,
            'status' => 'open',
            'opening_float' => '0.00',
            'opened_at' => now(),
        ]);
    }

    public function test_no_counter_payment_is_taken_without_an_open_shift(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", ['amount_paid' => 900, 'payment_method' => 'cash'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_open_cash_session');
    }

    public function test_cash_records_what_was_tendered_and_the_change_given(): void
    {
        $request = $this->allocatedRequest(2); // 1,800 owed
        $shift = $this->shiftFor($this->billingClerk, 100000);

        $this->payCash($request, 1800, ['amount_tendered' => 2000, 'payer_name' => 'Ana Reyes'])
            ->assertCreated()
            ->assertJsonPath('change_given', '200.00')
            ->assertJsonPath('receipt.payment.amount_tendered', '2000.00')
            ->assertJsonPath('receipt.payment.change_given', '200.00')
            ->assertJsonPath('receipt.payment.cash_session_number', $shift->session_number)
            ->assertJsonPath('billing.status', 'paid');

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertJsonPath('session.figures.cash_collected', '1800.00')
            ->assertJsonPath('session.figures.expected_cash', '2800.00')
            ->assertJsonPath('session.counts.payments', 1);

        // The counter reprints the receipt from its snapshot.
        $receiptId = PaymentReceipt::query()->sole()->id;

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/receipts/{$receiptId}")
            ->assertOk()
            ->assertJsonPath('receipt.payer_name', 'Ana Reyes')
            ->assertJsonPath('receipt.payment.change_given', '200.00');

        $this->actingAs($this->inventoryStaff)->getJson("/api/blood-center/receipts/{$receiptId}")->assertForbidden();
    }

    public function test_nothing_more_than_is_owed_is_recorded(): void
    {
        $request = $this->allocatedRequest(1); // 900 owed

        $this->payCash($request, 1000)
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_paid');

        $this->payCash($request, 900, ['amount_tendered' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_tendered');

        $this->payCash($request, 900, ['payment_method' => 'gcash', 'reference_number' => 'GC-1', 'amount_tendered' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_tendered');
    }

    public function test_gcash_at_the_counter_is_shown_beside_the_drawer_not_in_it(): void
    {
        $request = $this->allocatedRequest(1);
        $this->shiftFor($this->billingClerk, 50000);

        $this->payCash($request, 900, ['payment_method' => 'gcash', 'reference_number' => 'GC-REF-1'])->assertCreated();

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/session')
            ->assertJsonPath('session.figures.cash_collected', '0.00')
            ->assertJsonPath('session.figures.gcash_counter', '900.00')
            ->assertJsonPath('session.figures.expected_cash', '500.00')
            ->assertJsonPath('session.figures.total_collected', '900.00');
    }

    public function test_closing_freezes_the_count_and_a_difference_must_be_explained(): void
    {
        $request = $this->allocatedRequest(1);
        $shift = $this->shiftFor($this->billingClerk, 100000);
        $this->payCash($request, 900)->assertCreated();

        // 1,900 expected; 1,850 counted.
        $this->closeShift($this->billingClerk, $shift, 1850)
            ->assertStatus(422)
            ->assertJsonValidationErrors('closing_note');

        $this->closeShift($this->billingClerk, $shift, 1850, ['count_breakdown' => ['1000' => 1, '500' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('counted_cash');

        $this->closeShift($this->billingClerk, $shift, 1850, [
            'count_breakdown' => ['1000' => 1, '500' => 1, '100' => 3, '50' => 1],
            'closing_note' => 'A fifty went with the change.',
        ])
            ->assertOk()
            ->assertJsonPath('session.status', 'closed')
            ->assertJsonPath('session.figures.expected_cash', '1900.00')
            ->assertJsonPath('session.figures.counted_cash', '1850.00')
            ->assertJsonPath('session.figures.variance', '-50.00')
            ->assertJsonPath('session.closing_note', 'A fifty went with the change.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.shift_closed', 'actor_id' => $this->billingClerk->id]);

        // Closed means no more counter payments until a new shift.
        $next = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$next->id}/payments", ['amount_paid' => 900, 'payment_method' => 'cash'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_open_cash_session');
    }

    public function test_a_balanced_drawer_closes_without_a_note(): void
    {
        $shift = $this->shiftFor($this->billingClerk, 50000);

        $this->closeShift($this->billingClerk, $shift, 500)
            ->assertOk()
            ->assertJsonPath('session.figures.variance', '0.00');
    }

    public function test_a_closed_shift_never_changes(): void
    {
        $shift = $this->shiftFor($this->billingClerk);
        $this->closeShift($this->billingClerk, $shift, 0)->assertOk();

        $this->closeShift($this->billingClerk, $shift, 0)->assertStatus(409)->assertJsonPath('code', 'shift_closed');

        try {
            $shift->refresh()->update(['closing_note' => 'Changed afterwards.']);
            $this->fail('The model let a closed shift change.');
        } catch (LogicException) {
        }

        $this->expectException(QueryException::class);
        DB::table('cash_sessions')->where('id', $shift->id)->update(['counted_cash' => '999.00']);
    }

    public function test_only_the_cashier_or_a_billing_supervisor_closes_a_shift(): void
    {
        $shift = $this->shiftFor($this->billingClerk);
        $otherClerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();

        $this->closeShift($otherClerk, $shift, 0)->assertForbidden()->assertJsonPath('code', 'shift_not_yours');

        $this->closeShift($this->billingSupervisor, $shift, 0, ['closing_note' => 'Left open at end of day.'])
            ->assertOk()
            ->assertJsonPath('session.closed_by', trim($this->billingSupervisor->first_name.' '.$this->billingSupervisor->last_name));
    }

    public function test_a_shift_with_a_gcash_checkout_still_open_cannot_close(): void
    {
        $request = $this->allocatedRequest(1);
        $shift = $this->shiftFor($this->billingClerk);

        $revisionId = $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertCreated()
            ->json('revision.id');

        PaymentAttempt::query()->create([
            'billing_id' => $request->billing()->value('id'),
            'billing_revision_id' => $revisionId,
            'initiated_by' => $this->billingClerk->id,
            'initiator_facility_id' => $this->centre->id,
            'cash_session_id' => $shift->id,
            'provider' => 'xendit',
            'provider_account_id' => 'sub-account-test',
            'reference_id' => 'RA-TEST-1',
            'amount_centavos' => 90000,
            'currency' => 'PHP',
            'payer_name' => 'Ana',
            'status' => PaymentAttemptStatus::Active,
        ]);

        $this->closeShift($this->billingClerk, $shift, 0)
            ->assertStatus(409)
            ->assertJsonPath('code', 'checkout_open_on_shift');
    }

    public function test_cashiers_see_their_own_shifts_and_supervisors_see_all(): void
    {
        $mine = $this->shiftFor($this->billingClerk);
        $theirs = $this->shiftFor($this->billingSupervisor);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('may_oversee', false);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/pos/sessions/{$theirs->id}")
            ->assertNotFound();

        $this->actingAs($this->billingSupervisor)
            ->getJson('/api/blood-center/pos/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('may_oversee', true);
    }

    public function test_the_shift_report_prints(): void
    {
        $request = $this->allocatedRequest(1);
        $shift = $this->shiftFor($this->billingClerk, 10000);
        $this->payCash($request, 900, ['amount_tendered' => 1000])->assertCreated();
        $this->closeShift($this->billingClerk, $shift, 1000)->assertOk();

        $this->actingAs($this->billingClerk)
            ->get("/api/blood-center/pos/sessions/{$shift->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_counter_finds_patient_bills_and_never_weekly_ones(): void
    {
        $patient = $this->allocatedRequest(1);
        $weekly = $this->allocatedRequest(1, replenishment: true);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/lookup?q='.urlencode($patient->reference_number))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_id', $patient->id)
            ->assertJsonPath('data.0.outstanding', '900.00')
            ->assertJsonPath('data.0.takes_payment', true);

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/lookup?q='.urlencode($weekly->reference_number))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/pos/queue')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/pos/bills/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('bill.lines.0.quantity', 1)
            ->assertJsonPath('bill.lines.0.line_total', '900.00')
            ->assertJsonPath('bill.billing.status', 'unpaid');

        $this->actingAs($this->billingClerk)->getJson("/api/blood-center/pos/bills/{$weekly->id}")->assertNotFound();
    }

    public function test_the_counter_is_billing_staffs_alone(): void
    {
        $this->actingAs($this->inventoryStaff)->getJson('/api/blood-center/pos/session')->assertForbidden();
        $this->actingAs($this->inventoryStaff)->postJson('/api/blood-center/pos/sessions', ['opening_float' => 0])->assertForbidden();
        $this->actingAs($this->requester)->getJson('/api/blood-center/pos/queue')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function closeShift(User $staff, CashSession $shift, float|int $counted, array $extra = [])
    {
        return $this->actingAs($staff)->postJson("/api/blood-center/pos/sessions/{$shift->id}/close", [
            'counted_cash' => $counted,
            ...$extra,
        ]);
    }
}
