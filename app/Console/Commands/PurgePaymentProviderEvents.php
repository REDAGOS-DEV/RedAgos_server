<?php

namespace App\Console\Commands;

use App\Models\PaymentProviderEvent;
use Illuminate\Console\Command;

/**
 * Delete webhook delivery records older than the configured retention.
 *
 * The rows hold no raw body and no customer details, only hashes and the few
 * fields reconciliation needs, but they still describe payments, so they are
 * not kept for ever once a retention period is decided. Unset (the default
 * until the owner decides one), nothing is deleted. The payments, attempts and
 * receipts themselves are never touched.
 */
class PurgePaymentProviderEvents extends Command
{
    protected $signature = 'payments:purge-events';

    protected $description = 'Delete Xendit webhook delivery records older than XENDIT_EVENT_RETENTION_DAYS';

    public function handle(): int
    {
        $days = config('services.xendit.event_retention_days');

        if ($days === null || $days === '' || (int) $days < 1) {
            $this->info('No retention period is configured; nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = PaymentProviderEvent::query()
            ->where('received_at', '<', now()->subDays((int) $days))
            ->delete();

        $this->info("Deleted {$deleted} webhook record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
