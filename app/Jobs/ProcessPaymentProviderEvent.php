<?php

namespace App\Jobs;

use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use App\Service\PaymentEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process one stored Xendit webhook, off the request that received it.
 *
 * Xendit asks for a quick 2xx and retries anything slower, so the webhook
 * endpoint only stores the delivery and dispatches this. Durable on the
 * database queue: a failure is retried with growing delays, and one that
 * exhausts its tries lands in failed_jobs, flags the attempt for a person and
 * is logged. Reconciliation is the second net under it.
 */
class ProcessPaymentProviderEvent implements ShouldQueue
{
    use Queueable;

    /**
     * How many times the job is attempted.
     */
    public int $tries = 5;

    /**
     * Seconds before a stuck run is killed. Below the queue's retry_after (90).
     */
    public int $timeout = 60;

    /**
     * Create the job for one stored delivery.
     */
    public function __construct(public int $eventId) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    /**
     * Process the delivery, if it still exists.
     */
    public function handle(PaymentEventProcessor $processor): void
    {
        $event = PaymentProviderEvent::query()->find($this->eventId);

        if ($event !== null) {
            $processor->handle($event);
        }
    }

    /**
     * Record that the delivery could not be processed, and flag its attempt for a person.
     */
    public function failed(?Throwable $exception): void
    {
        $event = PaymentProviderEvent::query()->find($this->eventId);

        $event?->forceFill([
            'outcome' => 'error',
            'error' => substr((string) $exception?->getMessage(), 0, 255),
            'processed_at' => now(),
        ])->save();

        if ($event?->payment_attempt_id !== null) {
            PaymentAttempt::query()->whereKey($event->payment_attempt_id)->update([
                'review_required_at' => now(),
                'review_reason' => 'processing_failed',
                'updated_at' => now(),
            ]);
        }

        Log::error('xendit.webhook_processing_failed', [
            'event_id' => $this->eventId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
