<?php

namespace App\Service;

use App\Enums\PaymentAttemptStatus;
use App\Jobs\VerifyPaymentAttempt;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns what Xendit says into what RedAgos records — but only once the server has checked.
 *
 * A webhook is a claim. Nothing is recorded on its word: the attempt it names
 * is re-fetched from Xendit (GET /sessions/{id}) and the decision is driven by
 * that answer. The event name only says whether the claim was "it was paid",
 * which matters for one thing — when the re-fetch still says ACTIVE, a claimed
 * payment is retried rather than forgotten.
 *
 * Retryable verification. A completion Xendit has not yet reflected, or a
 * re-fetch that failed, moves the attempt to awaiting_verification and
 * schedules another look with a growing delay (1, 2, 5, 10, then 30 minutes)
 * until a deadline: the session's expiry plus a grace period. Past the
 * deadline the attempt is flagged for a person and keeps the statement
 * blocked — money may have moved, so it is never quietly dropped.
 *
 * Out of order. Completed is absorbing: a late expiry changes nothing. A late
 * completion — after expiry, after the statement changed, against a decided
 * statement — still records the money and flags it for review.
 */
class PaymentEventProcessor
{
    /**
     * Minutes between successive verification attempts.
     */
    private const BACKOFF_MINUTES = [1, 2, 5, 10, 30];

    /**
     * Minutes between checks on a checkout that is simply still open.
     */
    private const WATCH_MINUTES = 5;

    public function __construct(
        private readonly BillingService $billingService,
        private readonly XenditGateway $gateway
    ) {}

    /**
     * Process one stored webhook delivery.
     *
     * Safe to run twice: an event already processed is left alone, and the
     * attempt it names is verified against Xendit rather than taken on trust.
     */
    public function handle(PaymentProviderEvent $event): void
    {
        if ($event->processed_at !== null) {
            return;
        }

        $kind = $this->classify((string) $event->event_type);

        if ($kind === null) {
            $this->mark($event, 'ignored');
            Log::warning('xendit.webhook_unknown_event', ['event_id' => $event->id, 'event_type' => $event->event_type]);

            return;
        }

        $attempt = $this->match($event);

        if ($attempt === null) {
            $this->mark($event, 'unmatched');
            Log::warning('xendit.webhook_unmatched', ['event_id' => $event->id, 'event_type' => $event->event_type]);

            return;
        }

        $event->payment_attempt_id = $attempt->id;
        $attempt->forceFill(['last_event_at' => now()])->save();

        $attempt = $this->verify($attempt, $kind === 'completed');

        $this->mark($event, $attempt->status === PaymentAttemptStatus::AwaitingVerification ? 'pending_verification' : 'processed');
    }

    /**
     * Check an attempt against Xendit and record whatever it says.
     *
     * $claimedComplete is true when something — a webhook, a supervisor —
     * says the checkout was paid; a still-active session is then retried
     * rather than treated as merely open.
     */
    public function verify(PaymentAttempt $attempt, bool $claimedComplete = false): PaymentAttempt
    {
        if ($attempt->status === PaymentAttemptStatus::Completed || $attempt->provider_session_id === null) {
            return $attempt;
        }

        try {
            $session = $this->gateway->getSession($attempt->provider_account_id, $attempt->provider_session_id);
        } catch (RequestException|ConnectionException) {
            return $this->defer($attempt, 'provider_unreachable');
        }

        return match (strtoupper((string) ($session['status'] ?? ''))) {
            'COMPLETED' => $this->confirm($attempt, $session),
            'EXPIRED' => $this->close($attempt, PaymentAttemptStatus::Expired),
            'CANCELED' => $this->close($attempt, PaymentAttemptStatus::Canceled),
            default => $claimedComplete || $attempt->status === PaymentAttemptStatus::AwaitingVerification
                ? $this->defer($attempt, 'not_yet_completed')
                : $this->keepWatching($attempt),
        };
    }

    /**
     * Keep only what reconciliation needs from a webhook body.
     *
     * The field paths follow Xendit's documented payloads; which of them this
     * account actually sends is confirmed in the sandbox. Every id is tried
     * when matching, so a missing one only narrows the match.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function summarise(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $id = $this->string($data['id'] ?? null);

        return [
            'status' => $this->string($data['status'] ?? null),
            'amount_centavos' => $this->gateway->centavos($data['amount'] ?? $data['request_amount'] ?? null),
            'currency' => $this->string($data['currency'] ?? null),
            'reference_id' => $this->string($data['reference_id'] ?? null),
            'payment_session_id' => $this->string($data['payment_session_id'] ?? null)
                ?? ($id !== null && str_starts_with($id, 'ps-') ? $id : null),
            'payment_request_id' => $this->string($data['payment_request_id'] ?? null),
            'payment_id' => $this->string($data['payment_id'] ?? null),
            'failure_code' => $this->string($data['failure_code'] ?? null),
            'business_id' => $this->string($payload['business_id'] ?? null),
        ];
    }

    /**
     * Record a verified completion, unless what Xendit reports does not match the attempt.
     *
     * @param  array<string, mixed>  $session
     */
    private function confirm(PaymentAttempt $attempt, array $session): PaymentAttempt
    {
        $paymentId = $this->string($session['payment_id'] ?? null);

        $mismatch = match (true) {
            ($session['reference_id'] ?? null) !== $attempt->reference_id => 'reference_mismatch',
            strtoupper((string) ($session['currency'] ?? '')) !== $attempt->currency => 'currency_mismatch',
            $this->gateway->centavos($session['amount'] ?? null) !== $attempt->amount_centavos => 'amount_mismatch',
            $paymentId === null => 'payment_missing',
            default => null,
        };

        if ($mismatch !== null) {
            // Nothing is recorded: the figures Xendit returned are not the ones
            // this attempt asked for. A person has to look at it.
            $attempt->forceFill([
                'status' => PaymentAttemptStatus::Failed,
                'failure_code' => $mismatch,
                'next_verification_at' => null,
                'review_required_at' => now(),
                'review_reason' => $mismatch,
            ])->save();

            Log::error('xendit.verification_mismatch', [
                'payment_attempt_id' => $attempt->id,
                'reason' => $mismatch,
            ]);

            return $attempt;
        }

        $this->billingService->confirmGatewayPayment($attempt, $paymentId);

        return $attempt->refresh();
    }

    /**
     * End an attempt Xendit reports expired or cancelled, unless it was already completed.
     *
     * A superseded attempt stays superseded; it only stops being watched.
     */
    private function close(PaymentAttempt $attempt, PaymentAttemptStatus $to): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $to): PaymentAttempt {
            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentAttemptStatus::Completed) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $locked->status === PaymentAttemptStatus::Superseded ? PaymentAttemptStatus::Superseded : $to,
                'next_verification_at' => null,
                'review_required_at' => null,
                'review_reason' => null,
            ])->save();

            return $locked;
        });
    }

    /**
     * Schedule another verification, or flag the attempt for a person once the deadline has passed.
     */
    private function defer(PaymentAttempt $attempt, string $reason): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $reason): PaymentAttempt {
            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentAttemptStatus::Completed) {
                return $locked;
            }

            $deadline = $locked->verification_deadline_at
                ?? ($locked->expires_at ?? now()->toImmutable())->addMinutes((int) config('services.xendit.verification_grace_minutes', 60));

            if (now()->greaterThanOrEqualTo($deadline)) {
                $locked->forceFill([
                    'verification_deadline_at' => $deadline,
                    'next_verification_at' => null,
                    'review_required_at' => now(),
                    'review_reason' => 'verification_timeout',
                ])->save();

                Log::warning('xendit.verification_timeout', [
                    'payment_attempt_id' => $locked->id,
                    'last_reason' => $reason,
                ]);

                return $locked;
            }

            $count = $locked->verification_attempts + 1;
            $next = now()->addMinutes(self::BACKOFF_MINUTES[min($count, count(self::BACKOFF_MINUTES)) - 1]);

            $locked->forceFill([
                // An attempt superseded while the payer was paying stays
                // superseded; it is still checked until its money is known.
                'status' => $locked->status->isOpen() ? PaymentAttemptStatus::AwaitingVerification : $locked->status,
                'verification_attempts' => $count,
                'next_verification_at' => $next,
                'verification_deadline_at' => $deadline,
            ])->save();

            VerifyPaymentAttempt::dispatch($locked->id)->delay($next)->afterCommit();

            return $locked;
        });
    }

    /**
     * Note that an open, unclaimed checkout is still open, and look again later.
     *
     * Watched until a little past its expiry, so a payment whose webhook was
     * lost is still found by reconciliation.
     */
    private function keepWatching(PaymentAttempt $attempt): PaymentAttempt
    {
        $stopAt = ($attempt->expires_at ?? now()->toImmutable())->addMinutes((int) config('services.xendit.verification_grace_minutes', 60));

        $attempt->forceFill([
            'next_verification_at' => now()->greaterThanOrEqualTo($stopAt) ? null : now()->addMinutes(self::WATCH_MINUTES),
        ])->save();

        return $attempt;
    }

    /**
     * Which of completed, expired or failed an event name means, or null for one this account does not handle.
     */
    private function classify(string $eventType): ?string
    {
        foreach ((array) config('services.xendit.webhook_events', []) as $kind => $names) {
            if (in_array($eventType, (array) $names, true)) {
                return (string) $kind;
            }
        }

        return null;
    }

    /**
     * Find the attempt an event is about, by any of the ids it carries.
     */
    private function match(PaymentProviderEvent $event): ?PaymentAttempt
    {
        $summary = $event->summary ?? [];

        $candidates = [
            'provider_session_id' => $summary['payment_session_id'] ?? null,
            'provider_payment_request_id' => $summary['payment_request_id'] ?? null,
            'reference_id' => $summary['reference_id'] ?? null,
            'provider_payment_id' => $summary['payment_id'] ?? null,
        ];

        foreach ($candidates as $column => $value) {
            if ($value === null) {
                continue;
            }

            $attempt = PaymentAttempt::query()->where($column, $value)->first();

            if ($attempt !== null) {
                return $attempt;
            }
        }

        return null;
    }

    private function mark(PaymentProviderEvent $event, string $outcome, ?string $error = null): void
    {
        $event->forceFill([
            'outcome' => $outcome,
            'error' => $error,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * A short string from a webhook field, or null.
     */
    private function string(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : substr($text, 0, 64);
    }
}
