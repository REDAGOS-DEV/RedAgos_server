<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\BillingDiscount;
use App\Models\BillingRevision;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Service\BillingLedger;
use App\Service\StatementFigures;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * The centre's own discounts, kept by Billing and applied at the counter
 * beside the government subsidy (project owner, 2026-11-10).
 *
 * What is locked in: a percentage or a fixed peso amount; the note is
 * required; one discount per bill, and only before anything is collected;
 * the bill keeps its own copy, so editing the discount later changes no bill;
 * a percentage follows the units as they are tagged; the journal always adds
 * up; and the invoice says what was taken off.
 */
class BillingDiscountTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(750);
    }

    // ---- The centre's list ------------------------------------------------

    public function test_the_billing_supervisor_keeps_the_centres_list_of_discounts(): void
    {
        $this->actingAs($this->billingSupervisor)
            ->postJson('/api/blood-center/billing-discounts', ['name' => '  Senior   Citizen ', 'kind' => 'percentage', 'value' => 20])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Senior Citizen')
            ->assertJsonPath('data.label', '20% off')
            ->assertJsonPath('data.is_active', true);

        $charity = $this->actingAs($this->billingSupervisor)
            ->postJson('/api/blood-center/billing-discounts', ['name' => 'Charity', 'kind' => 'fixed', 'value' => 500])
            ->assertCreated()
            ->assertJsonPath('data.label', '₱500.00 off')
            ->json('data.id');

        $this->actingAs($this->billingSupervisor)
            ->putJson("/api/blood-center/billing-discounts/{$charity}", ['name' => 'Charity', 'kind' => 'fixed', 'value' => 600, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.value', 600)
            ->assertJsonPath('data.is_active', false);

        // Another centre's list is its own.
        $elsewhere = Facility::factory()->approved()->create();
        BillingDiscount::query()->create(['facility_id' => $elsewhere->id, 'name' => 'PWD', 'kind' => 'percentage', 'value' => 20]);

        $this->actingAs($this->billingSupervisor)
            ->getJson('/api/blood-center/billing-discounts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Senior Citizen');

        $this->assertTrue(AuditLog::query()->where('action', 'billing.discount_created')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'billing.discount_updated')->exists());
    }

    public function test_a_discount_must_be_a_sensible_amount_and_a_new_name(): void
    {
        $add = fn (array $payload) => $this->actingAs($this->billingSupervisor)->postJson('/api/blood-center/billing-discounts', $payload);

        $add(['name' => 'Too much', 'kind' => 'percentage', 'value' => 120])->assertJsonValidationErrors('value');
        $add(['name' => 'Nothing', 'kind' => 'fixed', 'value' => 0])->assertJsonValidationErrors('value');
        $add(['name' => 'Odd', 'kind' => 'half', 'value' => 10])->assertJsonValidationErrors('kind');

        $add(['name' => 'Senior Citizen', 'kind' => 'percentage', 'value' => 20])->assertCreated();
        $add(['name' => 'senior citizen', 'kind' => 'percentage', 'value' => 5])->assertJsonValidationErrors('name');
    }

    public function test_only_billing_keeps_the_list(): void
    {
        $this->actingAs($this->billingClerk)
            ->postJson('/api/blood-center/billing-discounts', ['name' => 'Staff', 'kind' => 'percentage', 'value' => 50])
            ->assertForbidden();
    }

    // ---- At the counter ---------------------------------------------------

    public function test_the_counter_offers_the_centres_switched_on_discounts(): void
    {
        $request = $this->allocatedRequest(2);
        $this->discount('Senior Citizen', 'percentage', 20);
        $this->discount('Retired', 'fixed', 100, active: false);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/pos/bills/{$request->id}")
            ->assertOk()
            ->assertJsonCount(1, 'bill.discounts')
            ->assertJsonPath('bill.discounts.0.name', 'Senior Citizen')
            ->assertJsonPath('bill.discounts.0.label', '20% off')
            ->assertJsonPath('bill.billing.discount', null);
    }

    public function test_the_bill_the_counter_opens_carries_the_choices_for_whoever_may_apply_them(): void
    {
        $request = $this->allocatedRequest(1);
        $senior = $this->discount('Senior Citizen', 'percentage', 20);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonCount(1, 'discounts')
            ->assertJsonPath('discounts.0.id', $senior->id)
            ->assertJsonPath('billing.discount_removable', false);

        // Issuance reads the bill to know whether units may go; it applies no discount.
        $this->actingAs($this->inventoryStaff)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonCount(0, 'discounts');

        $this->apply($request, $senior, 'OSCA 1')->assertOk();

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertJsonPath('billing.discount_removable', true)
            ->assertJsonPath('billing.discount.name', 'Senior Citizen');
    }

    public function test_a_percentage_comes_off_the_bill_and_the_journal_still_adds_up(): void
    {
        $request = $this->allocatedRequest(2); // 2 × ₱750 = ₱1,500
        $senior = $this->discount('Senior Citizen', 'percentage', 20);

        $this->apply($request, $senior, 'OSCA ID 12345')
            ->assertOk()
            ->assertJsonPath('message', 'Senior Citizen applied: ₱300.00 off.')
            ->assertJsonPath('billing.total_amount', 1200)
            ->assertJsonPath('billing.subtotal', 1500)
            ->assertJsonPath('billing.discount.name', 'Senior Citizen')
            ->assertJsonPath('billing.discount.amount', 300)
            ->assertJsonPath('billing.discount.note', 'OSCA ID 12345')
            ->assertJsonPath('statement.discount_name', 'Senior Citizen')
            ->assertJsonPath('statement.discount_amount', '300.00')
            ->assertJsonPath('statement.net_amount', '1200.00')
            ->assertJsonPath('statement.amount_due', '1200.00');

        $this->assertSame(BillingStatus::Unpaid, $this->billingOf($request)->status);
        $this->assertJournalAddsUp();
        $this->assertSame(1, BillingTransaction::query()->where('type', TransactionType::Discount->value)->count());

        $audit = AuditLog::query()->where('action', 'billing.discounted')->sole();
        $this->assertSame('OSCA ID 12345', $audit->context['note'] ?? null);
    }

    public function test_a_fixed_amount_never_takes_off_more_than_the_bill(): void
    {
        $request = $this->allocatedRequest(1); // ₱750
        $charity = $this->discount('Charity', 'fixed', 5000);

        $this->apply($request, $charity, 'Approved by the director')
            ->assertOk()
            ->assertJsonPath('billing.total_amount', 0)
            ->assertJsonPath('billing.discount.amount', 750)
            ->assertJsonPath('billing.clears_release', true);

        $this->assertSame(BillingStatus::Paid, $this->billingOf($request)->status);
        $this->assertJournalAddsUp();
    }

    public function test_the_note_is_required(): void
    {
        $request = $this->allocatedRequest(1);
        $senior = $this->discount('Senior Citizen', 'percentage', 20);

        $this->apply($request, $senior, '   ')->assertJsonValidationErrors('note');
        $this->assertFalse($this->billingOf($request)->hasDiscount());
    }

    public function test_only_this_centres_switched_on_discounts_can_be_applied(): void
    {
        $request = $this->allocatedRequest(1);
        $retired = $this->discount('Retired', 'percentage', 10, active: false);
        $elsewhere = BillingDiscount::query()->create([
            'facility_id' => Facility::factory()->approved()->create()->id,
            'name' => 'Theirs',
            'kind' => 'percentage',
            'value' => 50,
        ]);

        $this->apply($request, $retired, 'Card 1')->assertJsonValidationErrors('discount_id');
        $this->apply($request, $elsewhere, 'Card 1')->assertJsonValidationErrors('discount_id');
    }

    public function test_one_discount_per_bill(): void
    {
        $request = $this->allocatedRequest(2);
        $this->apply($request, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')->assertOk();

        $this->apply($request, $this->discount('PWD', 'percentage', 20), 'PWD 2')
            ->assertStatus(409)
            ->assertJsonPath('code', 'discount_already_applied');
    }

    public function test_no_discount_once_a_payment_has_been_taken(): void
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 500)->assertCreated();

        $this->apply($request, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')
            ->assertStatus(409)
            ->assertJsonPath('code', 'discount_after_payment');
    }

    public function test_the_subsidy_and_a_discount_are_never_on_one_bill(): void
    {
        $discounted = $this->allocatedRequest(1);
        $this->apply($discounted, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')->assertOk();

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$discounted->id}/subsidy")
            ->assertStatus(409)
            ->assertJsonPath('code', 'discount_applied');

        $subsidised = $this->allocatedRequest(1);
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$subsidised->id}/subsidy")
            ->assertOk();

        $this->apply($subsidised, BillingDiscount::query()->sole(), 'OSCA 2')
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_already_subsidised');
    }

    public function test_a_weekly_order_takes_no_discount(): void
    {
        $weekly = $this->allocatedRequest(1, replenishment: true);

        $this->apply($weekly, $this->discount('Senior Citizen', 'percentage', 20), 'n/a')
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_not_collectible');
    }

    public function test_a_percentage_follows_the_units_tagged_later(): void
    {
        $request = $this->allocatedRequest(2, askedFor: 3); // ₱1,500 so far
        $this->apply($request, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')->assertOk();

        $this->topUp($request, 1); // now ₱2,250

        $billing = $this->billingOf($request);
        $this->assertSame('450.00', $billing->discount_amount);
        $this->assertSame('1800.00', $billing->total_amount);
        $this->assertJournalAddsUp();
    }

    public function test_editing_a_discount_later_changes_no_bill_it_was_given_to(): void
    {
        $request = $this->allocatedRequest(2);
        $senior = $this->discount('Senior Citizen', 'percentage', 20);
        $this->apply($request, $senior, 'OSCA 1')->assertOk();

        $this->actingAs($this->billingSupervisor)
            ->putJson("/api/blood-center/billing-discounts/{$senior->id}", ['name' => 'Senior', 'kind' => 'percentage', 'value' => 50, 'is_active' => false])
            ->assertOk();

        $billing = $this->billingOf($request);
        $this->assertSame('Senior Citizen', $billing->discount_name);
        $this->assertSame('20.00', $billing->discount_value);
        $this->assertSame('1200.00', $billing->total_amount);
    }

    // ---- Taking it back off -----------------------------------------------

    public function test_a_discount_can_be_removed_before_anything_is_paid(): void
    {
        $request = $this->allocatedRequest(2);
        $this->apply($request, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')->assertOk();

        $this->actingAs($this->billingClerk)
            ->deleteJson("/api/blood-center/billings/{$request->id}/discount", ['reason' => 'Wrong patient.'])
            ->assertOk()
            ->assertJsonPath('billing.total_amount', 1500)
            ->assertJsonPath('billing.discount', null)
            ->assertJsonPath('statement.discount_amount', '0.00')
            ->assertJsonPath('statement.amount_due', '1500.00');

        $this->assertJournalAddsUp();
        $this->assertSame(1, BillingTransaction::query()->where('type', TransactionType::DiscountReversal->value)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'billing.discount_removed')->exists());
    }

    public function test_a_discount_stays_once_a_payment_has_been_taken(): void
    {
        $request = $this->allocatedRequest(2);
        $this->apply($request, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')->assertOk();
        $this->payCash($request, 200)->assertCreated();

        $this->actingAs($this->billingClerk)
            ->deleteJson("/api/blood-center/billings/{$request->id}/discount", ['reason' => 'Changed my mind.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'discount_after_payment');
    }

    public function test_a_discount_stays_once_a_unit_has_been_dispatched(): void
    {
        $request = $this->allocatedRequest(1);
        // The whole bill: nothing to pay, so the unit can go at once.
        $this->apply($request, $this->discount('Charity', 'fixed', 750), 'Director approved')->assertOk();

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->actingAs($this->billingClerk)
            ->deleteJson("/api/blood-center/billings/{$request->id}/discount", ['reason' => 'Too late.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'discount_removal_after_dispatch');
    }

    // ---- What it leaves behind --------------------------------------------

    public function test_the_invoice_keeps_the_discount_it_was_issued_with(): void
    {
        $request = $this->allocatedRequest(2);
        $this->apply($request, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')->assertOk();

        $revision = BillingRevision::query()->latest('id')->firstOrFail();
        $this->assertSame('Senior Citizen', $revision->discount_name);
        $this->assertSame('300.00', $revision->discount_amount);
        $this->assertSame('1500.00', $revision->total_amount);
        $this->assertSame('1200.00', $revision->amount_due);
    }

    public function test_financial_reports_count_the_discounts_given(): void
    {
        $request = $this->allocatedRequest(2);
        $this->apply($request, $this->discount('Senior Citizen', 'percentage', 20), 'OSCA 1')->assertOk();
        $today = OperationalDay::todayAsDate();

        $this->actingAs($this->billingSupervisor)
            ->getJson("/api/blood-center/reports/financial?from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonPath('summary.discounted', '300.00')
            ->assertJsonPath('discounts.count', 1)
            ->assertJsonPath('discounts.amount', '300.00');
    }

    // ---- helpers ----------------------------------------------------------

    private function discount(string $name, string $kind, int|float $value, bool $active = true): BillingDiscount
    {
        return BillingDiscount::query()->create([
            'facility_id' => $this->centre->id,
            'name' => $name,
            'kind' => $kind,
            'value' => $value,
            'is_active' => $active,
        ]);
    }

    private function apply(BloodRequest $request, BillingDiscount $discount, string $note)
    {
        return $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/discount", ['discount_id' => $discount->id, 'note' => $note]);
    }

    private function billingOf(BloodRequest $request): Billing
    {
        return Billing::query()->where('request_id', $request->id)->sole();
    }

    private function assertJournalAddsUp(): void
    {
        $this->assertEmpty(app(BillingLedger::class)->drift(app(StatementFigures::class))->all(), 'Every bill\'s journal must add up to its balance.');
    }
}
