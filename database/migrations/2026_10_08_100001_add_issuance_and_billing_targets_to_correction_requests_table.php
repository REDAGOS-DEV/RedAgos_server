<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a correction target something other than a donation.
     *
     * Until now every correction was about one donation's record, so
     * donation_id was NOT NULL. Issuance and Billing records have no donation
     * to hang a request on: a unit's details, a dispatched allocation and a
     * recorded payment each need a target of their own. Exactly one target is
     * set on every row — CorrectionRequest's saving hook enforces that
     * everywhere, and on PostgreSQL a CHECK constraint enforces it in the
     * database as well.
     *
     * One typed foreign key per target rather than a morph pair: a unit's key
     * is text and the others are bigint, and a shared id column would join one
     * of them wrongly on PostgreSQL while passing on SQLite (the audit log hit
     * exactly that, see widen_audit_log_auditable_id).
     *
     * MySQL is left to the model, as in allow_direct_distribution_blood_units:
     * it refuses a CHECK over a column whose foreign key carries a referential
     * action.
     *
     * Every constraint and index is named, so down() can drop each by name.
     * Kept as separate statements on purpose: sqlite rebuilds the table for a
     * column change, and adding a column in the same pass would ask it to
     * rebuild around a column it has not created yet.
     */
    public function up(): void
    {
        Schema::table('correction_requests', function (Blueprint $table): void {
            $table->foreignId('donation_id')->nullable()->change();
        });

        Schema::table('correction_requests', function (Blueprint $table): void {
            $table->string('blood_unit_id', 50)->nullable()->after('donation_id');
            $table->foreignId('request_allocation_id')->nullable()->after('blood_unit_id');
            $table->foreignId('payment_id')->nullable()->after('request_allocation_id');

            $table->foreign('blood_unit_id', 'correction_requests_blood_unit_id_foreign')
                ->references('id')->on('blood_units')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('request_allocation_id', 'correction_requests_request_allocation_id_foreign')
                ->references('id')->on('request_allocations')->cascadeOnDelete();
            $table->foreign('payment_id', 'correction_requests_payment_id_foreign')
                ->references('id')->on('payments')->cascadeOnDelete();

            // Serve the one-pending-request check, as the donation index does.
            $table->index(['blood_unit_id', 'subject', 'status'], 'correction_requests_unit_subject_status_index');
            $table->index(['request_allocation_id', 'subject', 'status'], 'correction_requests_allocation_subject_status_index');
            $table->index(['payment_id', 'subject', 'status'], 'correction_requests_payment_subject_status_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_one_target_check '
                .'CHECK (num_nonnulls(donation_id, blood_unit_id, request_allocation_id, payment_id) = 1)'
            );
        }
    }

    /**
     * Refused while any correction targets something other than a donation:
     * making donation_id required again would leave those rows with no target.
     *
     * The check runs before any schema change, so a refusal leaves the table
     * exactly as it was. After it, the CHECK goes first because it references
     * the target columns, then the foreign keys, then the indexes, then the
     * columns, and only then is donation_id made required again.
     */
    public function down(): void
    {
        if (DB::table('correction_requests')->whereNull('donation_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: corrections recorded against units, dispatches or payments have no donation to fall back on.'
            );
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE correction_requests DROP CONSTRAINT IF EXISTS correction_requests_one_target_check');
        }

        // By column, not by name: sqlite cannot drop a foreign key by name. The
        // names given in up() are the ones Laravel derives from the column, so
        // on PostgreSQL this drops exactly those constraints.
        Schema::table('correction_requests', function (Blueprint $table): void {
            $table->dropForeign(['blood_unit_id']);
            $table->dropForeign(['request_allocation_id']);
            $table->dropForeign(['payment_id']);
        });

        Schema::table('correction_requests', function (Blueprint $table): void {
            $table->dropIndex('correction_requests_unit_subject_status_index');
            $table->dropIndex('correction_requests_allocation_subject_status_index');
            $table->dropIndex('correction_requests_payment_subject_status_index');
        });

        Schema::table('correction_requests', function (Blueprint $table): void {
            $table->dropColumn(['blood_unit_id', 'request_allocation_id', 'payment_id']);
        });

        Schema::table('correction_requests', function (Blueprint $table): void {
            $table->foreignId('donation_id')->nullable(false)->change();
        });
    }
};
