<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who released a unit from quarantine, and when.
 *
 * Releasing is the Inventory Control Officer's act, and the final label they
 * print and affix says so. It was only in the audit log; on the unit it can be
 * printed and read back without searching the log. Nullable: units released
 * before this, and units never quarantined, have no release to name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blood_units', function (Blueprint $table) {
            $table->dateTime('released_at')->nullable()->after('status');
            $table->foreignId('released_by')->nullable()->after('released_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blood_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn('released_at');
        });
    }
};
