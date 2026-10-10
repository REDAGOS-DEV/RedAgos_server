<?php

namespace Tests\Concerns;

use App\Models\BloodRequest;
use App\Models\PaymentAttempt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * A stand-in for Xendit's Payment Sessions API, so no test can reach the real one.
 *
 * Creating a session returns ps-1, ps-2 and so on. Reading one answers with
 * whatever status the test has set, for the attempt that owns the session,
 * so a test can make Xendit say "still active", "completed" or "expired", or
 * report a different amount, at any point.
 */
trait FakesXendit
{
    protected string $sessionStatus = 'ACTIVE';

    protected ?int $sessionAmountCentavos = null;

    protected string $sessionCurrency = 'PHP';

    protected bool $sessionCreateFails = false;

    protected bool $sessionReadFails = false;

    protected int $sessionsCreated = 0;

    protected function fakeXendit(): void
    {
        config([
            'services.xendit.secret_key' => 'xnd_development_test_key',
            'services.xendit.webhook_token' => 'test-callback-token',
            'services.xendit.checkout_enabled' => true,
            'services.xendit.base_url' => 'https://api.xendit.co',
        ]);

        Http::preventStrayRequests();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.xendit.co/sessions') {
                if ($this->sessionCreateFails) {
                    return Http::response(['error_code' => 'API_VALIDATION_ERROR', 'message' => 'Rejected.'], 400);
                }

                $this->sessionsCreated++;
                $id = 'ps-'.$this->sessionsCreated;

                return Http::response([
                    'payment_session_id' => $id,
                    'reference_id' => $request['reference_id'],
                    'status' => 'ACTIVE',
                    'payment_link_url' => "https://checkout-staging.xendit.co/{$id}",
                    'expires_at' => now()->addMinutes(30)->utc()->format('Y-m-d\TH:i:s\Z'),
                    'payment_request_id' => null,
                ], 201);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.xendit.co/sessions/')) {
                if ($this->sessionReadFails) {
                    return Http::response(['error_code' => 'SERVER_ERROR'], 503);
                }

                $sessionId = basename((string) parse_url($request->url(), PHP_URL_PATH));
                $attempt = PaymentAttempt::query()->where('provider_session_id', $sessionId)->first();
                $centavos = $this->sessionAmountCentavos ?? $attempt?->amount_centavos ?? 0;

                return Http::response([
                    'payment_session_id' => $sessionId,
                    'reference_id' => $attempt?->reference_id,
                    'status' => $this->sessionStatus,
                    'amount' => $centavos / 100,
                    'currency' => $this->sessionCurrency,
                    'payment_id' => $this->sessionStatus === 'COMPLETED' ? "py-{$sessionId}" : null,
                    'payment_request_id' => "pr-{$sessionId}",
                ]);
            }

            return Http::response(['message' => 'Unexpected request in the Xendit fake.'], 500);
        });
    }

    protected function linkCentreToXendit(): void
    {
        $this->centre->forceFill(['xendit_sub_account_id' => 'sub-centre-1'])->save();
    }

    protected function openCheckout(BloodRequest $request, string $payer = 'Maria Santos'): PaymentAttempt
    {
        $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/checkout", ['payer_name' => $payer])
            ->assertCreated();

        return PaymentAttempt::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function webhook(array $payload, ?string $token = 'test-callback-token'): TestResponse
    {
        $headers = $token === null ? [] : ['x-callback-token' => $token];

        return $this->withHeaders($headers)->postJson('/api/webhooks/xendit', $payload);
    }

    /**
     * A session webhook for an attempt, shaped as Xendit documents it.
     *
     * @return array<string, mixed>
     */
    protected function sessionEvent(PaymentAttempt $attempt, string $event = 'payment_session.completed', string $status = 'COMPLETED'): array
    {
        return [
            'event' => $event,
            'business_id' => 'biz-master',
            'created' => now()->toIso8601String(),
            'data' => [
                'payment_session_id' => $attempt->provider_session_id,
                'reference_id' => $attempt->reference_id,
                'status' => $status,
                'amount' => $attempt->amount_centavos / 100,
                'currency' => 'PHP',
                'payment_id' => $status === 'COMPLETED' ? "py-{$attempt->provider_session_id}" : null,
            ],
        ];
    }
}
