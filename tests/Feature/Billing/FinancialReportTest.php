<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Financial Reports (project owner, 2026-10-25).
 *
 * What is locked in: collections net of voids, by method; what the subsidy
 * met; what patients and hospitals still owe, by age, and the hospitals'
 * settlements; the period's invoices by component and by hospital; and that
 * the Billing Supervisor and both management levels read it, a clerk not.
 */
class FinancialReportTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_it_adds_up_collections_receivables_components_and_hospitals(): void
    {
        $paid = $this->allocatedRequest(1);
        $this->payCash($paid, 900)->assertCreated();
        $this->allocatedRequest(1);

        $weekly = $this->allocatedRequest(2, replenishment: true);
        $this->actingAs($this->inventoryStaff)->postJson("/api/blood-center/blood-requests/{$weekly->id}/release")->assertOk();
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$weekly->id}/settlement", [
                'settlement_reference' => 'HOSP-CHK-7',
                'settled_at' => now(config('blood_center.timezone'))->toDateString(),
            ])
            ->assertOk();

        $response = $this->report()
            ->assertOk()
            ->assertJsonPath('summary.invoices', 3)
            ->assertJsonPath('summary.collected', '900.00')
            ->assertJsonPath('summary.settled_by_hospitals', '1800.00')
            ->assertJsonPath('summary.outstanding', '900.00')
            ->assertJsonPath('collections.payments', 1)
            ->assertJsonPath('receivables.patients.count', 1)
            ->assertJsonPath('receivables.patients.amount', '900.00')
            ->assertJsonPath('receivables.patients.by_age.0.count', 1)
            ->assertJsonPath('receivables.hospitals.count', 0)
            ->assertJsonPath('receivables.settlements.count', 1)
            ->assertJsonPath('by_component.0.name', 'Packed Red Blood Cells')
            ->assertJsonPath('by_component.0.units', 4)
            ->assertJsonPath('by_component.0.amount', '3600.00')
            ->assertJsonPath('by_hospital.0.facility_id', $this->hospital->id)
            ->assertJsonPath('by_hospital.0.invoices', 3)
            ->assertJsonPath('by_hospital.0.weekly_invoices', 1)
            ->assertJsonPath('by_hospital.0.billed', '3600.00')
            ->assertJsonPath('by_hospital.0.outstanding', '900.00');

        $cash = collect($response->json('collections.by_method'))->firstWhere('method', 'cash');
        $this->assertSame('900.00', $cash['amount']);
        $this->assertSame(1, $cash['payments']);
        $this->assertSame('day', $response->json('collections.granularity'));
    }

    public function test_the_billing_supervisor_and_both_management_levels_read_it_and_a_clerk_does_not(): void
    {
        $this->report($this->billingSupervisor)->assertOk();
        $this->report(User::factory()->bloodCenterAdmin($this->centre)->create())->assertOk();
        $this->report(User::factory()->bloodCenterSupervisor($this->centre)->create())->assertOk();
        $this->report($this->billingClerk)->assertForbidden();
    }

    public function test_a_period_runs_at_most_a_year(): void
    {
        $this->actingAs($this->billingSupervisor)
            ->getJson('/api/blood-center/reports/financial?from=2025-01-01&to=2026-06-30')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_a_long_period_is_shown_by_month(): void
    {
        $this->actingAs($this->billingSupervisor)
            ->getJson('/api/blood-center/reports/financial?from=2026-01-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('collections.granularity', 'month')
            ->assertJsonCount(6, 'collections.series');
    }

    private function report(?User $as = null): TestResponse
    {
        $today = now(config('blood_center.timezone'))->toDateString();

        return $this->actingAs($as ?? $this->billingSupervisor)
            ->getJson("/api/blood-center/reports/financial?from={$today}&to={$today}");
    }
}
