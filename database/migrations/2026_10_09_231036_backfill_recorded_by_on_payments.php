<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Copy who recorded each existing payment from the audit trail onto the payment.
     *
     * Before add_source_and_recorded_by_to_payments_table, BillingService
     * wrote the recorder only as the actor of the `billing.payment_recorded`
     * audit entry, whose context names the payment. This reads those entries
     * and fills the new column, so a payment recorded before deployment names
     * its recorder too.
     *
     * Data only, and decoded in PHP rather than with SQL JSON functions so the
     * same code runs on PostgreSQL and SQLite. A payment with no matching
     * entry — seeded, or written by hand — stays null, which means "recorded
     * before the recorder was stored", not "nobody".
     *
     * A migration cannot print to the console, so the counts go to the log,
     * together with any payment whose status is not completed: those were
     * recorded through an input that no longer exists, and need a person to
     * decide what they mean.
     */
    public function up(): void
    {
        $filled = 0;

        DB::table('audit_logs')
            ->where('action', 'billing.payment_recorded')
            ->whereNotNull('actor_id')
            ->orderBy('id')
            ->chunkById(200, function ($entries) use (&$filled): void {
                foreach ($entries as $entry) {
                    $paymentId = $this->paymentId($entry->context);

                    if ($paymentId === null) {
                        continue;
                    }

                    $filled += DB::table('payments')
                        ->where('id', $paymentId)
                        ->whereNull('recorded_by')
                        ->update(['recorded_by' => $entry->actor_id]);
                }
            });

        Log::info('payments.recorded_by backfill', [
            'filled' => $filled,
            'unknown' => DB::table('payments')->whereNull('recorded_by')->count(),
            'not_completed_by_status' => DB::table('payments')
                ->where('status', '!=', 'completed')
                ->groupBy('status')
                ->selectRaw('status, COUNT(*) as total')
                ->pluck('total', 'status')
                ->all(),
        ]);
    }

    /**
     * Data only: dropping the column in add_source_and_recorded_by_to_payments_table removes what this wrote.
     */
    public function down(): void
    {
        //
    }

    /**
     * The payment an audit entry names, or null when it names none.
     */
    private function paymentId(mixed $context): ?int
    {
        $decoded = json_decode((string) $context, true);
        $paymentId = is_array($decoded) ? ($decoded['payment_id'] ?? null) : null;

        if (is_int($paymentId)) {
            return $paymentId;
        }

        return is_string($paymentId) && ctype_digit($paymentId) ? (int) $paymentId : null;
    }
};
