<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pin each payment to the statement revision it settled against, and a gateway payment to its attempt.
     *
     * billing_revision_id: the frozen statement the payer was shown when they
     * paid, so a receipt can name it. Null on payments recorded before
     * statements were revisioned.
     *
     * payment_attempt_id: unique, so one confirmed checkout can never produce
     * two payments however often its webhook is delivered.
     *
     * provider: who confirmed a gateway payment. Null for a manual one.
     *
     * SQLite rebuilds a table for some column and foreign-key changes and drops
     * the table's triggers when it does, so the ledger triggers from
     * add_ledger_triggers_to_payments_table are re-created at the end. Their
     * SQL is repeated here on purpose: a migration must not depend on another
     * file's code.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('billing_revision_id')->nullable()->after('billing_id')
                ->constrained('billing_revisions')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('payment_attempt_id')->nullable()->unique()->after('billing_revision_id')
                ->constrained('payment_attempts')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('provider', 20)->nullable()->after('source');
        });

        $this->restoreSqliteLedgerTriggers();
    }

    /**
     * Refused while any gateway payment exists, which would lose the attempt that confirmed it.
     */
    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('payment_attempt_id')->exists()) {
            throw new RuntimeException('Cannot roll back: gateway payments would lose the checkout attempt that confirmed them.');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['payment_attempt_id']);
            $table->dropForeign(['billing_revision_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['payment_attempt_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['payment_attempt_id', 'billing_revision_id', 'provider']);
        });

        $this->restoreSqliteLedgerTriggers();
    }

    /**
     * Re-create the payments ledger triggers on SQLite, where a table rebuild drops them.
     */
    private function restoreSqliteLedgerTriggers(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payments_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS payments_guard_update');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_no_delete
            BEFORE DELETE ON payments
            BEGIN
                SELECT RAISE(ABORT, 'payments: a recorded payment cannot be deleted');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_guard_update
            BEFORE UPDATE ON payments
            WHEN NEW.source IS NOT OLD.source
                OR OLD.source = 'gateway'
                OR NEW.source = 'gateway'
            BEGIN
                SELECT RAISE(ABORT, 'payments: a payment''s source cannot change, and a gateway-confirmed payment cannot be updated');
            END
            SQL);
    }
};
