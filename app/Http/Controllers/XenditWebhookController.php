<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymentProviderEvent;
use App\Models\PaymentProviderEvent;
use App\Service\PaymentEventProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The endpoint Xendit delivers webhooks to.
 *
 * Reached only past VerifyXenditCallbackToken. It stores the delivery — its
 * hash and the few fields reconciliation needs, never the raw body — queues it
 * for processing and answers 2xx at once, as Xendit asks. A delivery already
 * stored (Xendit retries up to six times) is acknowledged and not queued
 * again.
 */
class XenditWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentEventProcessor $processor
    ) {}

    /**
     * Store one webhook delivery and queue it.
     */
    public function store(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'The webhook body is not JSON.', 'code' => 'malformed_webhook'], 400);
        }

        $hash = hash('sha256', $body);
        $summary = $this->processor->summarise($payload);
        $eventType = substr(trim((string) ($payload['event'] ?? $payload['type'] ?? '')), 0, 80);

        try {
            $event = PaymentProviderEvent::query()->create([
                'provider' => 'xendit',
                'event_type' => $eventType !== '' ? $eventType : null,
                'body_hash' => $hash,
                'dedupe_key' => $hash,
                'provider_object_id' => $summary['payment_session_id']
                    ?? $summary['payment_request_id']
                    ?? $summary['payment_id']
                    ?? $summary['reference_id'],
                'token_valid' => true,
                'summary' => $summary,
                'outcome' => 'received',
                'received_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                return response()->json(['received' => true, 'duplicate' => true]);
            }

            throw $exception;
        }

        ProcessPaymentProviderEvent::dispatch($event->id)->afterCommit();

        return response()->json(['received' => true]);
    }
}
