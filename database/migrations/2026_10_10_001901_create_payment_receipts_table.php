<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment Acknowledgement Receipts, one per confirmed payment.
     *
     * Issued only for money received — a completed payment, cash or gateway —
     * in the same transaction as the payment. Not a BIR official receipt; the
     * printed form says so. The snapshot holds everything the printed receipt
     * shows, frozen at issue, so a later top-up, subsidy or correction can
     * never change a receipt that was handed over.
     *
     * One *active* receipt per payment, by a partial unique index: an approved
     * correction to a manual payment voids its receipt and issues a new one
     * that names the receipt it replaces, so both stay on record.
     *
     * The database allows exactly one change to a receipt: a single update from
     * unvoided to fully voided — voided_at, voided_by and a non-empty
     * void_reason all set at once — with every other column unchanged. No
     * unvoiding, no partial void, no editing after the void, no delete.
     *
     * Values are literals rather than application enums.
     */
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('issuing_facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('receipt_number', 40);
            $table->foreignId('replaces_receipt_id')->nullable()->constrained('payment_receipts')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['issuing_facility_id', 'receipt_number']);
            $table->index('payment_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX payment_receipts_one_active_per_payment ON payment_receipts (payment_id) '
            .'WHERE voided_at IS NULL'
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
            DB::unprepared('DROP TRIGGER IF EXISTS payment_receipts_guard ON payment_receipts');
            DB::unprepared('DROP FUNCTION IF EXISTS payment_receipts_guard()');
        }

        // SQLite's triggers go with the table.
        Schema::dropIfExists('payment_receipts');
    }

    private function createTriggersOnPostgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_receipts_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'payment_receipts: a receipt cannot be deleted'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL OR OLD.voided_by IS NOT NULL OR OLD.void_reason IS NOT NULL
                    OR NEW.voided_at IS NULL OR NEW.voided_by IS NULL OR NEW.void_reason IS NULL
                    OR btrim(NEW.void_reason) = ''
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.payment_id IS DISTINCT FROM OLD.payment_id
                    OR NEW.issuing_facility_id IS DISTINCT FROM OLD.issuing_facility_id
                    OR NEW.receipt_number IS DISTINCT FROM OLD.receipt_number
                    OR NEW.replaces_receipt_id IS DISTINCT FROM OLD.replaces_receipt_id
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.issued_by IS DISTINCT FROM OLD.issued_by
                    OR NEW.snapshot::text IS DISTINCT FROM OLD.snapshot::text
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'payment_receipts: a receipt can only be voided, once and completely'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payment_receipts_guard
                BEFORE UPDATE OR DELETE ON payment_receipts
                FOR EACH ROW EXECUTE FUNCTION payment_receipts_guard()
            SQL);
    }

    private function createTriggersOnSqlite(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payment_receipts_no_delete
            BEFORE DELETE ON payment_receipts
            BEGIN
                SELECT RAISE(ABORT, 'payment_receipts: a receipt cannot be deleted');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payment_receipts_void_once
            BEFORE UPDATE ON payment_receipts
            WHEN OLD.voided_at IS NOT NULL OR OLD.voided_by IS NOT NULL OR OLD.void_reason IS NOT NULL
                OR NEW.voided_at IS NULL OR NEW.voided_by IS NULL OR NEW.void_reason IS NULL
                OR trim(NEW.void_reason) = ''
                OR NEW.id IS NOT OLD.id
                OR NEW.payment_id IS NOT OLD.payment_id
                OR NEW.issuing_facility_id IS NOT OLD.issuing_facility_id
                OR NEW.receipt_number IS NOT OLD.receipt_number
                OR NEW.replaces_receipt_id IS NOT OLD.replaces_receipt_id
                OR NEW.issued_at IS NOT OLD.issued_at
                OR NEW.issued_by IS NOT OLD.issued_by
                OR NEW.snapshot IS NOT OLD.snapshot
                OR NEW.created_at IS NOT OLD.created_at
            BEGIN
                SELECT RAISE(ABORT, 'payment_receipts: a receipt can only be voided, once and completely');
            END
            SQL);
    }
};
