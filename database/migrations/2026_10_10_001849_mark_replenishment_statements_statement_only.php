<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Mark the statements already raised against weekly orders as statement-only.
     *
     * Data only. A statement raised against a replenishment request becomes
     * statement_only unless somebody already decided it (void, subsidised) or
     * money was already collected against it. Those are left exactly as they
     * are and named in the log for a person to decide, because rewriting them
     * would hide a payment or overturn a decision.
     */
    public function up(): void
    {
        $candidates = DB::table('billings')
            ->join('blood_requests', 'blood_requests.id', '=', 'billings.request_id')
            ->where('blood_requests.request_purpose', 'replenishment')
            ->whereNotIn('billings.status', ['void', 'subsidised', 'statement_only'])
            ->pluck('billings.id');

        $collected = DB::table('payments')
            ->whereIn('billing_id', $candidates)
            ->where('status', 'completed')
            ->distinct()
            ->pluck('billing_id');

        $marked = DB::table('billings')
            ->whereIn('id', $candidates->diff($collected)->values())
            ->update(['status' => 'statement_only', 'updated_at' => now()]);

        Log::info('billings statement_only backfill', [
            'marked' => $marked,
            'left_with_payments' => $collected->values()->all(),
        ]);
    }

    /**
     * Data only: rolling back add_statement_only_status_to_billings_table refuses while these rows exist.
     */
    public function down(): void
    {
        //
    }
};
