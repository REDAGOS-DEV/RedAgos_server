<?php

namespace Tests\Feature\Billing;

use App\Enums\Department;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * A hospital's Statement of Account for a period (project owner, 2026-10-25).
 *
 * What is locked in: only what the hospital owes is on it — its weekly
 * replenishment invoices and its settlements, never a patient's bill; the
 * opening balance carries what was owed before the period; the balance runs
 * through the activity to the closing balance; it is generated on demand and
 * numbered nowhere; and it is read with billing.view, within the caller's
 * own centre.
 */
class AccountStatementTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(900);
    }

    public function test_it_shows_the_hospitals_weekly_invoices_its_settlements_and_what_is_left(): void
    {
        $settled = $this->dispatchedWeekly(2);
        $this->settle($settled);
        $open = $this->allocatedRequest(1, replenishment: true);
        $patient = $this->allocatedRequest(1);

        $today = now(config('blood_center.timezone'))->toDateString();

        $response = $this->statement($today, $today)
            ->assertOk()
            ->assertJsonPath('hospital.id', $this->hospital->id)
            ->assertJsonPath('opening_balance', '0.00')
            ->assertJsonPath('invoiced', '2700.00')
            ->assertJsonPath('payments', '1800.00')
            ->assertJsonPath('closing_balance', '900.00');

        $references = collect($response->json('invoices'))->pluck('request_reference');
        $this->assertEqualsCanonicalizing([$settled->reference_number, $open->reference_number], $references->all());
        $this->assertNotContains($patient->reference_number, $references->all(), 'A patient bill is the patient\'s, not the hospital\'s.');

        $activity = collect($response->json('activity'));
        $this->assertSame('900.00', $activity->last()['balance']);
        $this->assertContains('external_settlement', $activity->pluck('type')->all());
    }

    public function test_the_opening_balance_carries_what_was_owed_before_the_period(): void
    {
        $this->allocatedRequest(1, replenishment: true);

        $tomorrow = now(config('blood_center.timezone'))->addDay()->toDateString();

        $this->statement($tomorrow, $tomorrow)
            ->assertOk()
            ->assertJsonPath('opening_balance', '900.00')
            ->assertJsonPath('invoiced', '0.00')
            ->assertJsonPath('closing_balance', '900.00')
            ->assertJsonCount(0, 'activity')
            // Still open at the period's end, so it is listed.
            ->assertJsonCount(1, 'invoices');
    }

    public function test_the_hospitals_it_can_be_drawn_for_are_those_billed_for_replenishment(): void
    {
        $this->allocatedRequest(1, replenishment: true);
        $patientsOnly = Facility::factory()->bloodBank()->approved()->create();

        $this->actingAs($this->billingClerk)
            ->getJson('/api/blood-center/account-statements/hospitals')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->hospital->id)
            ->assertJsonMissing(['id' => $patientsOnly->id]);
    }

    public function test_another_centre_sees_none_of_this_centres_billing(): void
    {
        $this->allocatedRequest(1, replenishment: true);
        $elsewhere = User::factory()->bloodCenterStaff(null, Department::Billing)->create();
        $today = now(config('blood_center.timezone'))->toDateString();

        $this->statement($today, $today, $elsewhere)
            ->assertOk()
            ->assertJsonPath('closing_balance', '0.00')
            ->assertJsonCount(0, 'invoices');
    }

    public function test_it_is_read_with_billing_view(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();
        $today = now(config('blood_center.timezone'))->toDateString();

        $this->statement($today, $today, $collection)->assertForbidden();
    }

    public function test_the_period_must_run_forward(): void
    {
        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/account-statements?hospital_id={$this->hospital->id}&from=2026-10-10&to=2026-10-01")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_it_prints_as_a_pdf(): void
    {
        $this->allocatedRequest(1, replenishment: true);
        $today = now(config('blood_center.timezone'))->toDateString();

        $response = $this->actingAs($this->billingClerk)
            ->get("/api/blood-center/account-statements/pdf?hospital_id={$this->hospital->id}&from={$today}&to={$today}");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    private function statement(string $from, string $to, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->billingClerk)
            ->getJson("/api/blood-center/account-statements?hospital_id={$this->hospital->id}&from={$from}&to={$to}");
    }

    private function dispatchedWeekly(int $units): BloodRequest
    {
        $request = $this->allocatedRequest($units, replenishment: true);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        return $request;
    }

    private function settle(BloodRequest $request): void
    {
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/settlement", [
                'settlement_reference' => 'HOSP-CHK-0042',
                'settled_at' => now(config('blood_center.timezone'))->toDateString(),
            ])
            ->assertOk();
    }
}
