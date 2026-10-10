<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\PaymentAttemptStatus;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\Concerns\FakesXendit;
use Tests\TestCase;

/**
 * GCash checkouts billing staff open at the counter for a patient's watcher.
 *
 * The amount always comes from a frozen statement revision, never the request;
 * one checkout per statement is open at a time; only the watcher's name goes
 * to Xendit; and cash, subsidy and a changed statement each give way to it in
 * the right order.
 */
class GatewayCheckoutTest extends TestCase
{
    use BuildsBillingFixtures, FakesXendit, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(500);
        $this->fakeXendit();
        $this->linkCentreToXendit();
    }

    public function test_a_checkout_opens_for_the_statements_amount_on_the_centres_own_sub_account(): void
    {
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('attempt.status', 'active')
            ->assertJsonPath('attempt.amount', '1000.00')
            ->assertJsonPath('attempt.checkout_url', 'https://checkout-staging.xendit.co/ps-1');

        Http::assertSent(function (Request $sent) use ($request): bool {
            $body = $sent->data();

            return $sent->url() === 'https://api.xendit.co/sessions'
                && $sent->hasHeader('for-user-id', 'sub-centre-1')
                && $sent->hasHeader('Authorization', 'Basic '.base64_encode('xnd_development_test_key:'))
                && $body['amount'] === 1000.0
                && $body['currency'] === 'PHP'
                && $body['allowed_payment_channels'] === ['GCASH']
                && $body['customer']['individual_detail']['given_names'] === 'Maria Santos'
                // Nothing from the patient record goes to the provider.
                && ! str_contains(json_encode($body), (string) $request->patient_surname);
        });

        $attempt = PaymentAttempt::query()->sole();

        $this->assertNotNull($attempt->billing_revision_id);
        $this->assertSame('Maria Santos', $attempt->payer_name);
        $this->assertNotSame('Maria Santos', DB::table('payment_attempts')->value('payer_name'), 'The payer name is encrypted at rest.');
    }

    public function test_the_amount_cannot_be_sent_by_the_caller(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos', 'amount' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        Http::assertNothingSent();
    }

    public function test_the_payer_name_is_required(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payer_name']);
    }

    public function test_opening_twice_returns_the_checkout_already_open(): void
    {
        $request = $this->allocatedRequest(1);
        $first = $this->openCheckout($request);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Someone Else'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('attempt.id', $first->id);

        $this->assertSame(1, $this->sessionsCreated);
    }

    public function test_checkout_is_refused_while_switched_off(): void
    {
        config(['services.xendit.checkout_enabled' => false]);
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'checkout_disabled');

        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    public function test_checkout_is_refused_at_a_centre_without_a_sub_account(): void
    {
        $this->centre->forceFill(['xendit_sub_account_id' => null])->save();
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'merchant_not_configured');
    }

    public function test_a_local_test_lets_a_centre_without_a_sub_account_collect_into_the_master_account(): void
    {
        config(['services.xendit.allow_main_account' => true]);
        $this->centre->forceFill(['xendit_sub_account_id' => null])->save();
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertJsonPath('checkout.available', true);

        $attempt = $this->openCheckout($request);
        $this->assertSame('main', $attempt->provider_account_id);

        $this->sessionStatus = 'COMPLETED';
        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->refresh()->status);
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST' && ! $sent->hasHeader('for-user-id'));
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'GET' && ! $sent->hasHeader('for-user-id'));
        Http::assertNotSent(fn (Request $sent): bool => $sent->hasHeader('for-user-id'));
    }

    public function test_the_master_account_is_never_used_with_a_live_key(): void
    {
        config([
            'services.xendit.allow_main_account' => true,
            'services.xendit.secret_key' => 'xnd_production_live_key',
        ]);
        $this->centre->forceFill(['xendit_sub_account_id' => null])->save();
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertJsonPath('checkout.reason', 'merchant_not_configured');

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'merchant_not_configured');

        Http::assertNothingSent();
    }

    public function test_a_centre_with_its_own_sub_account_keeps_using_it_when_the_test_switch_is_on(): void
    {
        config(['services.xendit.allow_main_account' => true]);
        $request = $this->allocatedRequest(1);

        $this->assertSame('sub-centre-1', $this->openCheckout($request)->provider_account_id);
        Http::assertSent(fn (Request $sent): bool => $sent->hasHeader('for-user-id', 'sub-centre-1'));
    }

    public function test_checkout_is_refused_for_a_weekly_order_and_for_a_statement_with_nothing_owed(): void
    {
        $weekly = $this->allocatedRequest(1, replenishment: true);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$weekly->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_not_collectible');

        $request = $this->allocatedRequest(1);
        $this->payCash($request, 500)->assertCreated();

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_settled');
    }

    public function test_a_balance_above_the_gcash_limit_is_refused(): void
    {
        $this->priceComponent(60000);
        $request = $this->allocatedRequest(2);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'exceeds_channel_limit');
    }

    public function test_a_provider_failure_fails_the_attempt_and_frees_the_statement_for_cash(): void
    {
        $this->sessionCreateFails = true;
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'gateway_unavailable');

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->status);
        $this->assertSame('API_VALIDATION_ERROR', $attempt->failure_code);

        $this->payCash($request, 500)->assertCreated();
    }

    public function test_cash_and_subsidy_wait_while_a_checkout_is_open(): void
    {
        $request = $this->allocatedRequest(1);
        $this->openCheckout($request);

        $this->payCash($request, 500)->assertStatus(409)->assertJsonPath('code', 'payment_in_progress');

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_in_progress');
    }

    public function test_staff_can_supersede_an_open_checkout_and_then_take_cash(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/attempts/{$attempt->id}/supersede", ['reason' => 'Watcher paying cash instead.'])
            ->assertOk()
            ->assertJsonPath('attempt.status', 'superseded')
            ->assertJsonPath('attempt.checkout_url', null);

        $this->payCash($request, 500)->assertCreated();
    }

    public function test_a_change_to_the_statement_supersedes_the_open_checkout(): void
    {
        $request = $this->allocatedRequest(1, askedFor: 2);
        $attempt = $this->openCheckout($request);

        $this->topUp($request, 1);

        $this->assertSame(PaymentAttemptStatus::Superseded, $attempt->refresh()->status);
        $this->assertSame('statement_changed', $attempt->failure_code);
    }

    public function test_the_statement_says_whether_a_checkout_can_be_opened_and_shows_the_open_one(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('checkout.available', true)
            ->assertJsonPath('open_attempt', null);

        $attempt = $this->openCheckout($request);

        $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}")
            ->assertOk()
            ->assertJsonPath('checkout.available', false)
            ->assertJsonPath('checkout.reason', 'payment_in_progress')
            ->assertJsonPath('open_attempt.id', $attempt->id);
    }

    public function test_the_attempts_list_never_shows_the_payers_name(): void
    {
        $request = $this->allocatedRequest(1);
        $this->openCheckout($request, 'Very Private Name');

        $body = $this->actingAs($this->billingClerk)
            ->getJson("/api/blood-center/billings/{$request->id}/attempts")
            ->assertOk()
            ->assertJsonCount(1, 'attempts')
            ->json();

        $this->assertStringNotContainsString('Very Private Name', json_encode($body));
    }

    public function test_only_the_billing_supervisor_resolves_a_checkout(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/attempts/{$attempt->id}/close", ['reason' => 'Abandoned at the counter.'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_billing_supervisor');

        $this->actingAs($this->billingSupervisor)
            ->postJson("/api/blood-center/billings/{$request->id}/attempts/{$attempt->id}/close", ['reason' => 'Abandoned at the counter.'])
            ->assertOk()
            ->assertJsonPath('attempt.status', 'canceled');
    }

    public function test_a_checkout_the_provider_shows_as_paid_cannot_be_closed(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionStatus = 'COMPLETED';

        $this->actingAs($this->billingSupervisor)
            ->postJson("/api/blood-center/billings/{$request->id}/attempts/{$attempt->id}/close", ['reason' => 'Looks abandoned.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'provider_shows_payment');

        // Re-checking it instead records the money.
        $this->actingAs($this->billingSupervisor)
            ->postJson("/api/blood-center/billings/{$request->id}/attempts/{$attempt->id}/reverify")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'completed');
    }

    public function test_another_centres_statement_cannot_be_checked_out(): void
    {
        $request = $this->allocatedRequest(1);
        $stranger = User::factory()->bloodCenterStaff(
            Facility::factory()->approved()->create(['xendit_sub_account_id' => 'sub-other']),
            StaffRole::BillingClerk
        )->create();

        $this->actingAs($stranger)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => 'Maria Santos'])
            ->assertNotFound();
    }
}
