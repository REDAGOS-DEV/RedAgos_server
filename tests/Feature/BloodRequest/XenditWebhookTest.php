<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BillingStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentSource;
use App\Jobs\ProcessPaymentProviderEvent;
use App\Models\Payment;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\Concerns\FakesXendit;
use Tests\TestCase;

/**
 * Xendit webhooks: authenticated, stored, queued, and believed only once re-checked.
 *
 * The queue runs synchronously in tests, so a webhook is processed during the
 * request that delivered it; the job's own retry and failure paths are tested
 * directly.
 */
class XenditWebhookTest extends TestCase
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

    public function test_a_webhook_without_the_callback_token_is_refused_and_stores_nothing_but_its_hash(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        $this->webhook($this->sessionEvent($attempt), 'wrong-token')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_callback_token');

        $this->webhook($this->sessionEvent($attempt), null)->assertUnauthorized();

        $this->assertSame(2, PaymentProviderEvent::query()->where('outcome', 'rejected')->whereNull('dedupe_key')->whereNull('summary')->count());
        $this->assertSame(0, Payment::query()->count());

        // A forged copy sent first does not make the genuine one a duplicate.
        $this->sessionStatus = 'COMPLETED';
        $this->webhook($this->sessionEvent($attempt))->assertOk()->assertJsonMissingPath('duplicate');
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_no_webhook_is_accepted_while_no_token_is_configured(): void
    {
        config(['services.xendit.webhook_token' => null]);

        $this->webhook(['event' => 'payment_session.completed', 'data' => []], 'anything')->assertUnauthorized();
    }

    public function test_a_verified_completion_records_one_gateway_payment_with_its_receipt_and_clears_release(): void
    {
        $request = $this->allocatedRequest(2);
        $attempt = $this->openCheckout($request, 'Maria Santos');
        $this->sessionStatus = 'COMPLETED';

        $this->webhook($this->sessionEvent($attempt))->assertOk()->assertJsonPath('received', true);

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentSource::Gateway, $payment->source);
        $this->assertSame($attempt->id, $payment->payment_attempt_id);
        $this->assertSame('1000.00', $payment->amount_paid);
        $this->assertSame("py-{$attempt->provider_session_id}", $payment->reference_number);
        $this->assertNull($payment->recorded_by);

        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->refresh()->status);
        $this->assertSame(BillingStatus::Paid, $request->billing()->firstOrFail()->status);

        $receipt = PaymentReceipt::query()->sole();
        $this->assertSame('Maria Santos', $receipt->snapshot['payer_name']);
        $this->assertNull($receipt->snapshot['received_by']);
        $this->assertSame('0.00', $receipt->snapshot['balance_after']);

        $this->assertSame('processed', PaymentProviderEvent::query()->sole()->outcome);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();
    }

    public function test_the_same_delivery_six_times_records_the_money_once(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionStatus = 'COMPLETED';
        $event = $this->sessionEvent($attempt);

        $this->webhook($event)->assertOk();

        for ($retry = 0; $retry < 5; $retry++) {
            $this->webhook($event)->assertOk()->assertJsonPath('duplicate', true);
        }

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame(1, PaymentProviderEvent::query()->count());
    }

    public function test_a_different_event_about_an_already_completed_attempt_changes_nothing(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionStatus = 'COMPLETED';

        $this->webhook($this->sessionEvent($attempt))->assertOk();
        $this->webhook($this->sessionEvent($attempt, 'payment.succeeded'))->assertOk();
        $this->webhook($this->sessionEvent($attempt, 'payment_session.expired', 'EXPIRED'))->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->refresh()->status);
    }

    public function test_a_claimed_completion_xendit_has_not_reflected_yet_is_retried_not_dropped(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        // The webhook says paid; a re-fetch still says active.
        $this->sessionStatus = 'ACTIVE';
        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::AwaitingVerification, $attempt->status);
        $this->assertSame(1, $attempt->verification_attempts);
        $this->assertNotNull($attempt->next_verification_at);
        $this->assertSame('pending_verification', PaymentProviderEvent::query()->sole()->outcome);
        $this->assertSame(0, Payment::query()->count());

        // Cash waits: money may already be on its way.
        $this->payCash($request, 500)->assertStatus(409)->assertJsonPath('code', 'payment_in_progress');

        // Xendit catches up; reconciliation finds it.
        $this->sessionStatus = 'COMPLETED';
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->refresh()->status);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_provider_that_cannot_be_reached_is_retried_too(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionReadFails = true;

        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $this->assertSame(PaymentAttemptStatus::AwaitingVerification, $attempt->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_past_the_deadline_an_unconfirmed_claim_is_flagged_for_a_person_and_keeps_cash_blocked(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->webhook($this->sessionEvent($attempt))->assertOk();

        // Still active at Xendit, long after the session should have closed.
        $this->travel(3)->hours();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $attempt->refresh();
        $this->assertNotNull($attempt->review_required_at);
        $this->assertSame('verification_timeout', $attempt->review_reason);
        $this->assertSame(PaymentAttemptStatus::AwaitingVerification, $attempt->status);
        $this->assertSame(0, Payment::query()->count());

        $this->payCash($request, 500)->assertStatus(409)->assertJsonPath('code', 'payment_in_progress');
    }

    public function test_figures_that_do_not_match_the_attempt_record_nothing_and_ask_for_review(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionStatus = 'COMPLETED';
        $this->sessionAmountCentavos = 100;

        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $attempt->refresh();
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->status);
        $this->assertSame('amount_mismatch', $attempt->review_reason);
    }

    public function test_a_currency_other_than_pesos_records_nothing(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionStatus = 'COMPLETED';
        $this->sessionCurrency = 'USD';

        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('currency_mismatch', $attempt->refresh()->review_reason);
    }

    public function test_an_event_name_this_account_does_not_handle_is_ignored_without_asking_xendit(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionStatus = 'COMPLETED';

        $this->webhook($this->sessionEvent($attempt, 'payment.awaiting_capture'))->assertOk();

        $this->assertSame('ignored', PaymentProviderEvent::query()->sole()->outcome);
        $this->assertSame(0, Payment::query()->count());
        Http::assertSentCount(1); // only the session creation
    }

    public function test_an_event_for_no_known_checkout_is_marked_unmatched(): void
    {
        $this->webhook([
            'event' => 'payment_session.completed',
            'data' => ['payment_session_id' => 'ps-unknown', 'reference_id' => 'RA-NOPE', 'status' => 'COMPLETED', 'amount' => 1, 'currency' => 'PHP'],
        ])->assertOk();

        $this->assertSame('unmatched', PaymentProviderEvent::query()->sole()->outcome);
    }

    public function test_an_expired_checkout_closes_and_a_late_completion_is_still_recorded_for_review(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        $this->sessionStatus = 'EXPIRED';
        $this->webhook($this->sessionEvent($attempt, 'payment_session.expired', 'EXPIRED'))->assertOk();
        $this->assertSame(PaymentAttemptStatus::Expired, $attempt->refresh()->status);

        // Delivered out of order: the payment had gone through after all.
        $this->sessionStatus = 'COMPLETED';
        $this->webhook($this->sessionEvent($attempt, 'payment.succeeded'))->assertOk();

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->status);
        $this->assertSame('late_completion', $attempt->review_reason);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_money_that_arrives_after_a_subsidy_is_recorded_without_undoing_the_subsidy(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/attempts/{$attempt->id}/supersede", ['reason' => 'Subsidy approved.'])
            ->assertOk();
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/subsidy")
            ->assertOk();

        // The watcher paid the old link anyway.
        $this->sessionStatus = 'COMPLETED';
        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(BillingStatus::Subsidised, $request->billing()->firstOrFail()->status);
        $this->assertSame('statement_settled_by_decision', $attempt->refresh()->review_reason);
    }

    public function test_a_gateway_payment_cannot_be_corrected(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);
        $this->sessionStatus = 'COMPLETED';
        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $payment = Payment::query()->sole();

        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/payments/{$payment->id}/corrections", [
                'reason' => 'Looks wrong.',
                'changes' => ['amount_paid' => 400, 'payment_method' => 'gcash', 'reference_number' => 'X-1'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'gateway_payment_not_correctable');
    }

    public function test_a_body_that_is_not_json_is_refused(): void
    {
        // call() does not apply withHeaders(), so the token goes in as a server variable.
        $this->call('POST', '/api/webhooks/xendit', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CALLBACK_TOKEN' => 'test-callback-token',
        ], 'not json')
            ->assertStatus(400)
            ->assertJsonPath('code', 'malformed_webhook');
    }

    public function test_the_webhook_queues_its_processing_rather_than_doing_it_in_the_request(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        Queue::fake();

        $this->webhook($this->sessionEvent($attempt))->assertOk();

        Queue::assertPushed(ProcessPaymentProviderEvent::class, 1);
        $this->assertSame('received', PaymentProviderEvent::query()->sole()->outcome);
    }

    public function test_a_job_that_exhausts_its_tries_marks_the_event_and_flags_the_checkout(): void
    {
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        Queue::fake();
        $this->webhook($this->sessionEvent($attempt))->assertOk();

        $event = PaymentProviderEvent::query()->sole();
        $event->forceFill(['payment_attempt_id' => $attempt->id])->save();

        (new ProcessPaymentProviderEvent($event->id))->failed(new RuntimeException('database went away'));

        $this->assertSame('error', $event->refresh()->outcome);
        $this->assertSame('processing_failed', $attempt->refresh()->review_reason);
    }

    public function test_the_job_retries_with_growing_delays(): void
    {
        $job = new ProcessPaymentProviderEvent(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 120, 300, 900], $job->backoff());
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);
    }
}
