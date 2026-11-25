<?php

namespace Tests\Feature\Billing;

use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Lines already on a bill when prices were first locked (2026-11-26).
 *
 * Locked at the price their latest issued invoice showed, which is what the
 * payer was given, or else at the centre's present price; a line not billed
 * yet is left to be priced when it is.
 */
class BilledPriceBackfillTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_26_000002_add_billed_unit_price_to_blood_request_items.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(750);
    }

    public function test_billed_lines_are_locked_at_their_invoice_price_or_the_present_one(): void
    {
        $invoiced = $this->allocatedRequest(1);
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$invoiced->id}/statements")
            ->assertCreated();

        $uninvoiced = $this->allocatedRequest(1);

        // Not billed: nothing tagged against it yet.
        $waiting = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, 1)
            ->create();

        $this->priceComponent(1000);

        $migration = require database_path(self::MIGRATION);
        $migration->down();
        $migration->up();

        $this->assertSame('750.00', $this->lineOf($invoiced->id)->billed_unit_price);
        $this->assertSame('1000.00', $this->lineOf($uninvoiced->id)->billed_unit_price);
        $this->assertNotNull($this->lineOf($uninvoiced->id)->priced_at);
        $this->assertNull($this->lineOf($waiting->id)->billed_unit_price);
    }

    private function lineOf(int $requestId): BloodRequestItem
    {
        return BloodRequestItem::query()->where('request_id', $requestId)->firstOrFail();
    }
}
