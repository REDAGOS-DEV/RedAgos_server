<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the payments table a delete-protected, correction-controlled ledger in the database itself.
     *
     * Three rules, enforced below the application so that a query-builder
     * update, a script or a direct SQL session is bound by them too:
     *
     *  - no payment is ever deleted;
     *  - a payment's source never changes, in either direction;
     *  - a gateway payment is never updated, and no update may produce one.
     *
     * The last two together mean a gateway row can only come into existence by
     * INSERT. A manual row cannot be relabelled as gateway and then edited,
     * nor a gateway row relabelled manual to slip into the correction path.
     *
     * Manual payments stay updatable, because the approved correction workflow
     * (CorrectionService, then BillingService::correctPayment) changes their
     * amount, method or reference in place. That is why this is not called
     * append-only. Payment's model hooks state the same rules earlier, with a
     * clearer error.
     *
     * The values are literals rather than the PaymentSource enum, for the
     * reason given in add_source_and_recorded_by_to_payments_table.
     *
     * PostgreSQL and SQLite only. MySQL is left to the model, as
     * allow_direct_distribution_blood_units leaves its CHECK.
     *
     * SQLite drops a table's triggers when it rebuilds the table, which Laravel
     * does for many column and foreign-key changes. Any later migration that
     * alters `payments` on SQLite must re-create these triggers, or the test
     * suite stops exercising them.
     */
    public function up(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->createOnPostgres(),
            'sqlite' => $this->createOnSqlite(),
            default => null,
        };
    }

    /**
     * Drop the triggers, leaving the rules to the model alone.
     */
    public function down(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->dropOnPostgres(),
            'sqlite' => $this->dropOnSqlite(),
            default => null,
        };
    }

    private function createOnPostgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payments_guard_ledger() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'payments: a recorded payment cannot be deleted'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.source IS DISTINCT FROM OLD.source
                    OR OLD.source = 'gateway'
                    OR NEW.source = 'gateway' THEN
                    RAISE EXCEPTION 'payments: a payment''s source cannot change, and a gateway-confirmed payment cannot be updated'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_guard_ledger
                BEFORE UPDATE OR DELETE ON payments
                FOR EACH ROW EXECUTE FUNCTION payments_guard_ledger()
            SQL);
    }

    private function dropOnPostgres(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payments_guard_ledger ON payments');
        DB::unprepared('DROP FUNCTION IF EXISTS payments_guard_ledger()');
    }

    private function createOnSqlite(): void
    {
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

    private function dropOnSqlite(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payments_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS payments_guard_update');
    }
};
