<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record where each payment's evidence came from and who took it, and keep payments when a statement goes.
     *
     * `source` says whether billing staff recorded the payment (manual) or a
     * payment provider confirmed it (gateway). The two are corrected
     * differently, so the row has to say which it is. Its values are written
     * out here rather than read from the PaymentSource enum: a migration that
     * has run must keep meaning the same thing whatever the application code
     * later becomes. Every existing row is a manual one — no gateway has ever
     * been connected — and takes that value from the column default.
     *
     * `recorded_by` is the staff member who recorded a manual payment. Until
     * now that was only in the audit log; backfill_recorded_by_on_payments
     * copies it across. A gateway payment has nobody to name and stays null.
     *
     * The statement foreign key moves from cascade to restrict on delete. A
     * payment is the record that money was received, and deleting a statement
     * must not take that record with it.
     *
     * Kept as separate statements on purpose: SQLite rebuilds the table for a
     * foreign-key change, and adding a column in the same pass would ask it to
     * rebuild around a column it has not created yet.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['billing_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('billing_id')->references('id')->on('billings')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('source', ['manual', 'gateway'])->default('manual')->after('status');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->after('source')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    /**
     * Refused while any gateway payment exists, which the earlier table could not tell apart from a manual one.
     *
     * The check runs before any schema change, so a refusal leaves the table
     * exactly as it was.
     */
    public function down(): void
    {
        if (DB::table('payments')->where('source', 'gateway')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: gateway-confirmed payments would become indistinguishable from payments recorded by staff.'
            );
        }

        // By column, not by name: SQLite cannot drop a foreign key by name.
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['recorded_by']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['recorded_by', 'source']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['billing_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('billing_id')->references('id')->on('billings')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }
};
