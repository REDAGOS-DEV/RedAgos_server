<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A donor accepted with a vital sign out of its range (project owner,
 * 2026-11-27): the physician may still accept, but says why, and the reason
 * stays on the screening. Null when every reading was in range or the donor
 * was deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->string('out_of_range_reason', 255)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropColumn('out_of_range_reason');
        });
    }
};
