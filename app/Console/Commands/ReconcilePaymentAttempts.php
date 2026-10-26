<?php

namespace App\Console\Commands;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Service\PaymentEventProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The second net under the webhook path: look at every checkout that is due a check.
 *
 * Webhooks are the primary signal and are processed by a queued job. This
 * runs every minute and re-fetches, through the same processor, each checkout
 * whose next_verification_at has come: open ones nobody has heard about for a
 * while (a lost webhook), ones awaiting verification, and superseded ones
 * still payable at Xendit until they expire. An attempt flagged for review is
 * left to a person.
 *
 * It also catches a checkout whose provider call never finished — still
 * creating after ten minutes — and flags it, because a session may exist at
 * Xendit that RedAgos never heard about; and it reports failed processing
 * jobs, so a stuck queue is noticed.
 */
class ReconcilePaymentAttempts extends Command
{
    /**
     * How many attempts one run looks at, so a backlog cannot make a run overlap the next.
     */
    private const BATCH = 100;

    protected $signature = 'payments:reconcile';

    protected $description = 'Re-check GCash checkouts that are due a verification against Xendit';

    public function __construct(
        private readonly PaymentEventProcessor $processor
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $due = PaymentAttempt::query()
            ->whereIn('status', [
                PaymentAttemptStatus::Active,
                PaymentAttemptStatus::AwaitingVerification,
                PaymentAttemptStatus::Superseded,
            ])
            ->whereNull('review_required_at')
            ->whereNotNull('provider_session_id')
            ->whereNotNull('next_verification_at')
            ->where('next_verification_at', '<=', now())
            ->orderBy('next_verification_at')
            ->limit(self::BATCH)
            ->get();

        $checked = 0;

        foreach ($due as $attempt) {
            try {
                $this->processor->verify($attempt, $attempt->status === PaymentAttemptStatus::AwaitingVerification);
                $checked++;
            } catch (Throwable $exception) {
                // One attempt failing must not stop the rest of the run.
                Log::error('payments.reconcile_attempt_failed', [
                    'payment_attempt_id' => $attempt->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $stranded = PaymentAttempt::query()
            ->where('status', PaymentAttemptStatus::Creating)
            ->whereNull('provider_session_id')
            ->whereNull('review_required_at')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => PaymentAttemptStatus::Failed->value,
                'failure_code' => 'session_not_confirmed',
                'review_required_at' => now(),
                'review_reason' => 'session_unknown',
                'updated_at' => now(),
            ]);

        $failedJobs = DB::table('failed_jobs')
            ->where(fn ($query) => $query
                ->where('payload', 'like', '%ProcessPaymentProviderEvent%')
                ->orWhere('payload', 'like', '%VerifyPaymentAttempt%'))
            ->count();

        if ($stranded > 0 || $failedJobs > 0) {
            Log::warning('payments.reconcile_needs_attention', [
                'stranded_checkouts' => $stranded,
                'failed_payment_jobs' => $failedJobs,
            ]);
        }

        $this->info("Checked {$checked} checkout(s); flagged {$stranded} stranded; {$failedJobs} failed payment job(s) on record.");

        return self::SUCCESS;
    }
}
