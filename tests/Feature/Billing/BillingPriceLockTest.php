<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingStatus;
use App\Enums\TransactionType;
use App\Models\BillingTransaction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * A price change applies to new bills only (project owner, 2026-11-26).
 *
 * A bill already raised is a past transaction, made at the old price. Each
 * of its lines keeps the price it was first billed at: on the bill, at the
 * counter, on an invoice issued later, in the Financial Reports, and for
 * units tagged on that line afterwards. A request first billed after the
 * change is charged the new price.
 */
class BillingPriceLockTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(750);
    }

    public function test_a_bill_raised_before_a_price_change_keeps_its_price(): void
    {
        $request = $this->allocatedRequest(2);

        $this->changePrice(1000);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('billing.lines.0.unit_price', '750.00')
            ->assertJsonPath('billing.lines.0.line_total', '1500.00')
            ->assertJsonPath('billing.outstanding', '1500.00');

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/pos/bills/{$request->id}")
            ->assertOk()
            ->assertJsonPath('bill.lines.0.unit_price', '750.00');

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertCreated()
            ->assertJsonPath('revision.lines.0.unit_price', '750.00')
            ->assertJsonPath('revision.amount_due', '1500.00');

        $this->payCash($request, 1500)->assertCreated();
        $this->assertSame(BillingStatus::Paid, $request->billing()->value('status'));
    }

    public function test_units_tagged_later_on_a_billed_line_go_on_at_its_price(): void
    {
        $request = $this->allocatedRequest(1, askedFor: 3);

        $this->changePrice(1000);
        $this->topUp($request, 2);

        $this->assertSame('2250.00', (string) $request->billing()->value('total_amount'));

        // Only the units were added; nothing was repriced.
        $charges = BillingTransaction::query()
            ->where('request_id', $request->id)
            ->where('type', TransactionType::Charge->value)
            ->orderBy('id')
            ->pluck('amount')
            ->map(fn ($amount): string => number_format((float) $amount, 2, '.', ''))
            ->all();

        $this->assertSame(['750.00', '1500.00'], $charges);
        $this->assertFalse(BillingTransaction::query()
            ->where('request_id', $request->id)
            ->where('type', TransactionType::ChargeAdjustment->value)
            ->exists());
    }

    public function test_a_request_billed_after_the_change_is_charged_the_new_price(): void
    {
        $before = $this->allocatedRequest(1);

        $this->changePrice(1000);

        $after = $this->allocatedRequest(1);

        $this->assertSame('750.00', (string) $before->billing()->value('total_amount'));
        $this->assertSame('1000.00', (string) $after->billing()->value('total_amount'));
    }

    public function test_the_financial_report_counts_each_bill_at_the_price_it_was_billed(): void
    {
        $this->allocatedRequest(1);
        $this->changePrice(1000);
        $this->allocatedRequest(1);

        $today = now(config('blood_center.timezone'))->toDateString();

        $this->actingAs($this->billingSupervisor)
            ->getJson("/api/blood-center/reports/financial?from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonPath('by_component.0.units', 2)
            ->assertJsonPath('by_component.0.amount', '1750.00');
    }

    public function test_saving_prices_says_bills_already_raised_are_unchanged(): void
    {
        $this->actingAs($this->billingSupervisor)
            ->putJson('/api/blood-center/component-prices', ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 1000, 'loaded_price' => 750],
            ]])
            ->assertOk()
            ->assertJsonPath('message', "1 price saved for {$this->centre->name}. Bills already raised keep their prices.");
    }

    /**
     * Change the centre's price, as Billing does from Component Prices.
     */
    private function changePrice(int $amount): void
    {
        $this->priceComponent($amount);
    }
}
