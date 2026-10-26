<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which payments a receipt acknowledges.
     *
     * A payment split across methods at the counter gets one receipt, not one
     * per method (project owner, 2026-10-15), so a receipt can now cover
     * several payments. This table names every one of them, the first
     * included. payment_receipts.payment_id stays and names the first, so
     * whatever reads a receipt's payment still finds it: the request it
     * belongs to, the hospital's list.
     *
     * Each existing receipt covered one payment and is carried in that way.
     *
     * Append-only, like the receipts it indexes. A receipt's payments are
     * fixed when it is issued. A correction or a void issues a new receipt
     * with its own rows, and never changes these.
     */
    public function up(): void
    {
        Schema::create('payment_receipt_payments', function (Blueprint $table) {
            $table->foreignId('payment_receipt_id')->constrained('payment_receipts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnUpdate()->restrictOnDelete();

            $table->primary(['payment_receipt_id', 'payment_id']);
            $table->index('payment_id');
        });

        DB::statement(
            'INSERT INTO payment_receipt_payments (payment_receipt_id, payment_id) SELECT id, payment_id FROM payment_receipts'
        );

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->createTriggersOnPostgres(),
            'sqlite' => $this->createTriggersOnSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_receipt_payments_append_only ON payment_receipt_payments');
            DB::unprepared('DROP FUNCTION IF EXISTS payment_receipt_payments_refuse_change()');
        }

        // SQLite's triggers go with the table.
        Schema::dropIfExists('payment_receipt_payments');
    }

    private function createTriggersOnPostgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_receipt_payments_refuse_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'payment_receipt_payments: the payments a receipt covers cannot be changed or deleted'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payment_receipt_payments_append_only
                BEFORE UPDATE OR DELETE ON payment_receipt_payments
                FOR EACH ROW EXECUTE FUNCTION payment_receipt_payments_refuse_change()
            SQL);
    }

    private function createTriggersOnSqlite(): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'payment_receipt_payments_no_'.strtolower($operation);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}
                BEFORE {$operation} ON payment_receipt_payments
                BEGIN
                    SELECT RAISE(ABORT, 'payment_receipt_payments: the payments a receipt covers cannot be changed or deleted');
                END
                SQL);
        }
    }
};
