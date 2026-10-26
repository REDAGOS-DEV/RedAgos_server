<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment statuses after this migration, and before it.
     *
     * Written out rather than read from the PaymentStatus enum, so this
     * migration keeps meaning the same thing whatever the enum later becomes.
     */
    private const ACCEPTED = ['pending', 'completed', 'failed', 'refunded', 'voided'];

    private const PREVIOUS = ['pending', 'completed', 'failed', 'refunded'];

    /**
     * Tie each counter payment to the cash shift that took it, and let an approved void be recorded.
     *
     * cash_session_id: the shift whose drawer the payment went into, or whose
     * cashier opened the GCash checkout. Null on payments from before shifts.
     *
     * amount_tendered, change_given: the cash the payer handed over and the
     * change given back, so a shift's drawer can be reconciled and the receipt
     * can show both. The payment itself is never more than was outstanding.
     *
     * voided_at, voided_by, void_reason and the `voided` status: an entry
     * voided on the Billing Supervisor's approval, while its shift was still
     * open. The row stays — the ledger never loses a payment — and stops
     * counting as collected.
     *
     * SQLite rebuilds the table for the foreign key and the status change and
     * drops its triggers when it does, so the ledger triggers from
     * add_ledger_triggers_to_payments_table are re-created at the end. Their
     * SQL is repeated here on purpose: a migration must not depend on another
     * file's code.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('cash_session_id')->nullable()->after('payment_attempt_id')
                ->constrained('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->decimal('amount_tendered', 12, 2)->nullable()->after('amount_paid');
            $table->decimal('change_given', 12, 2)->nullable()->after('amount_tendered');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('voided_by')->nullable()->after('voided_at')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            $table->index('cash_session_id');
        });

        $this->setAcceptedStatuses(self::ACCEPTED);

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->foreignId('cash_session_id')->nullable()
                ->constrained('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
        });

        $this->restoreSqliteLedgerTriggers();
    }

    /**
     * Refused while any payment was voided or taken in a shift, which the earlier table cannot say.
     */
    public function down(): void
    {
        if (DB::table('payments')->where('status', 'voided')->orWhereNotNull('cash_session_id')->exists()) {
            throw new RuntimeException('Cannot roll back: payments were voided or taken in a cash shift, which the earlier table cannot record.');
        }

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_session_id');
        });

        $this->setAcceptedStatuses(self::PREVIOUS);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['cash_session_id']);
            $table->dropForeign(['cash_session_id']);
            $table->dropForeign(['voided_by']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['cash_session_id', 'amount_tendered', 'change_given', 'voided_at', 'voided_by', 'void_reason']);
        });

        $this->restoreSqliteLedgerTriggers();
    }

    /**
     * Rewrite the set of values the status column accepts.
     *
     * PostgreSQL swaps its CHECK constraint, SQLite rebuilds the column, as in
     * add_statement_only_status_to_billings_table.
     *
     * @param  array<int, string>  $accepted
     */
    private function setAcceptedStatuses(array $accepted): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(
                fn (string $value): string => $connection->getPdo()->quote($value),
                $accepted
            ));

            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ({$quoted}))");

            return;
        }

        Schema::table('payments', function (Blueprint $table) use ($accepted): void {
            $table->enum('status', $accepted)->default('completed')->change();
        });
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
