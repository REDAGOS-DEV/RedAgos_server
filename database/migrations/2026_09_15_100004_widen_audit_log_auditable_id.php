<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let the audit trail hold a string subject key.
     *
     * auditable_id was declared unsignedBigInteger, which fits every model in
     * the application except the one the inventory and allocation modules audit
     * most: BloodUnit's primary key is the varchar(50) printed on the physical
     * bag, such as "RA4-118-01". AuditLogger::record() writes $subject->getKey()
     * straight into this column, so every inventory.recorded, inventory.
     * discarded and inventory.expired row already attempts a string into a
     * bigint. PostgreSQL rejects that outright; SQLite, which the test suite
     * uses, coerces it silently, which is why no test caught it.
     *
     * Widening to a string is the fix that keeps the existing rows meaningful:
     * an integer key casts to its own text form without loss, so historical
     * rows keep pointing at the same records.
     *
     * 64 characters rather than 50 so the column is not sized to today's
     * longest key; auditable_type is already 100.
     */
    public function up(): void
    {
        // Dropped and rebuilt around the type change because SQLite implements
        // a column change as a table rebuild, and an index over the column
        // being rebuilt does not reliably survive it.
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['auditable_type', 'auditable_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('auditable_id', 64)->nullable()->change();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    /**
     * Narrow the column back to an integer key.
     *
     * This fails against any row holding a non-numeric key, which is the
     * correct outcome: those rows are precisely the ones the old column could
     * never store.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['auditable_type', 'auditable_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('auditable_id')->nullable()->change();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['auditable_type', 'auditable_id']);
        });
    }
};
