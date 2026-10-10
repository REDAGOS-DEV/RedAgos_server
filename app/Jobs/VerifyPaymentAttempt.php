<?php

namespace App\Jobs;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Service\PaymentEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Look at a checkout again once its next verification is due.
 *
 * Dispatched with a delay whenever a verification has to wait. It checks the
 * attempt's own next_verification_at before doing anything, so a queue that
 * ignores delays (the sync driver) cannot spin it, and a run that arrives
 * after reconciliation already settled the attempt does nothing.
 */
class VerifyPaymentAttempt implements ShouldQueue
{
    use Queueable;

    /**
     * How many times the job is attempted.
     */
    public int $tries = 3;

    /**
     * Seconds before a stuck run is killed. Below the queue's retry_after (90).
     */
    public int $timeout = 60;

    /**
     * Create the job for one attempt.
     */
    public function __construct(public int $attemptId) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Verify the attempt, if a verification is due and nobody has taken it over.
     */
    public function handle(PaymentEventProcessor $processor): void
    {
        $attempt = PaymentAttempt::query()->find($this->attemptId);

        if ($attempt === null
            || $attempt->review_required_at !== null
            || $attempt->next_verification_at === null
            || $attempt->next_verification_at->isFuture()) {
            return;
        }

        $processor->verify($attempt, $attempt->status === PaymentAttemptStatus::AwaitingVerification);
    }

    /**
     * Flag the attempt for a person once verification itself keeps failing.
     */
    public function failed(?Throwable $exception): void
    {
        PaymentAttempt::query()->whereKey($this->attemptId)->update([
            'review_required_at' => now(),
            'review_reason' => 'verification_failed',
            'updated_at' => now(),
        ]);

        Log::error('xendit.verification_failed', [
            'payment_attempt_id' => $this->attemptId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
