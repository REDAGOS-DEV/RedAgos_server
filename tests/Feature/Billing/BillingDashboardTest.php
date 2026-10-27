<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * The Billing dashboard (project owner, 2026-10-27).
 *
 * What is locked in: it reads the counter's own figures (collected today,
 * what is owed and how old, 30 days of collections) the same as the billing
 * summary and Financial Reports, lists the hospitals that owe the most, and
 * is the counter's to read: a clerk and the supervisor yes, Issuance not.
 */
class BillingDashboardTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    private const URL = '/api/blood-center/billing-dashboard';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_it_adds_up_today_what_is_owed_and_the_last_30_days(): void
    {
        $paid = $this->allocatedRequest(1);
        $this->payCash($paid, 900)->assertCreated();
        $this->allocatedRequest(1);

        $weekly = $this->allocatedRequest(2, replenishment: true);
        $this->actingAs($this->inventoryStaff)->postJson("/api/blood-center/blood-requests/{$weekly->id}/release")->assertOk();

        $response = $this->actingAs($this->billingClerk)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('today.collected_today', '900.00')
            ->assertJsonPath('today.payments_today', 1)
            ->assertJsonPath('today.outstanding.count', 1)
            ->assertJsonPath('collections.total', '900.00')
            ->assertJsonPath('collections.granularity', 'day')
            ->assertJsonPath('receivables.patients.amount', '900.00')
            ->assertJsonPath('receivables.hospitals.count', 1)
            ->assertJsonPath('top_hospitals.0.facility_id', $this->hospital->id)
            ->assertJsonPath('top_hospitals.0.invoices', 1)
            ->assertJsonPath('top_hospitals.0.amount', '1800.00');

        $this->assertCount(30, $response->json('collections.series'));
    }

    public function test_the_counter_reads_it_and_issuance_does_not(): void
    {
        $this->actingAs($this->billingSupervisor)->getJson(self::URL)->assertOk();
        $this->actingAs($this->inventoryStaff)->getJson(self::URL)->assertForbidden();
    }
}
