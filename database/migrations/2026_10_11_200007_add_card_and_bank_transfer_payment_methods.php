<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment methods after this migration, and before it.
     *
     * Written out rather than read from the PaymentMethod enum, so this
     * migration keeps meaning the same thing whatever the enum later becomes.
     */
    private const ACCEPTED = ['cash', 'gcash', 'card', 'bank_transfer'];

    private const PREVIOUS = ['cash', 'gcash'];

    /**
     * Let the counter record a card payment and a bank transfer.
     *
     * Added by the project owner on 2026-10-11. Both are recorded from their
     * reference, as a GCash transfer at the counter is: the card terminal's
     * reference, or the bank's transfer reference. RedAgos checks neither with
     * a provider.
     *
     * The method is held in two columns, payments.payment_method and the
     * journal's billing_transactions.payment_method. PostgreSQL swaps each CHECK
     * constraint; SQLite rebuilds each column, as in
     * add_cash_shift_and_void_to_payments_table, and drops both tables'
     * triggers when it does, so they are re-created at the end. Their SQL is
     * repeated here on purpose: a migration must not depend on another file's
     * code.
     */
    public function up(): void
    {
        $this->setAccepted(self::ACCEPTED);
    }

    /**
     * Narrow the columns back, refusing rather than rewriting card or bank-transfer rows.
     */
    public function down(): void
    {
        $added = array_values(array_diff(self::ACCEPTED, self::PREVIOUS));
        $payments = DB::table('payments')->whereIn('payment_method', $added)->count();
        $journal = DB::table('billing_transactions')->whereIn('payment_method', $added)->count();

        if ($payments + $journal > 0) {
            throw new RuntimeException(
                "{$payments} payment(s) and {$journal} journal row(s) were taken by card or bank transfer, which the "
                .'earlier columns cannot express. Decide what each one should become before rolling this back.'
            );
        }

        $this->setAccepted(self::PREVIOUS);
    }

    /**
     * Rewrite the set of values both payment_method columns accept.
     *
     * @param  array<int, string>  $accepted
     */
    private function setAccepted(array $accepted): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(
                fn (string $value): string => $connection->getPdo()->quote($value),
                $accepted
            ));

            // The journal's column is nullable: a charge has no method, and NULL passes the check.
            foreach (['payments', 'billing_transactions'] as $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_payment_method_check");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_payment_method_check CHECK (payment_method IN ({$quoted}))");
            }

            return;
        }

        Schema::table('payments', function (Blueprint $table) use ($accepted): void {
            $table->enum('payment_method', $accepted)->change();
        });

        Schema::table('billing_transactions', function (Blueprint $table) use ($accepted): void {
            $table->enum('payment_method', $accepted)->nullable()->change();
        });

        $this->restoreSqliteTriggers();
    }

    /**
     * Re-create the payments and journal triggers on SQLite, where a table rebuild drops them.
     */
    private function restoreSqliteTriggers(): void
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

        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'billing_transactions_no_'.strtolower($operation);

            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}
                BEFORE {$operation} ON billing_transactions
                BEGIN
                    SELECT RAISE(ABORT, 'billing_transactions: a billing transaction cannot be changed or deleted');
                END
                SQL);
        }
    }
};
