<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last day of a temporary deferral made at the counter (project owner,
 * 2026-10-24: the counter defers through the Deferrals page's form, Until
 * date included).
 *
 * Null for every other outcome, for a drive's paper record, and for rows from
 * before: those temporary deferrals still hold until the donor is screened
 * again (DonorDeferralRepository::deferralsFor()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->date('deferred_until')->nullable()->after('deferral_reason');
        });
    }

    public function down(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropColumn('deferred_until');
        });
    }
};
