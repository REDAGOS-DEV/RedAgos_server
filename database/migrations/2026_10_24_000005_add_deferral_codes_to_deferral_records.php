<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deferral code beside the reason on the two records that hold one: the
 * counter's (and a drive paper's) screening, and a deferral recorded on the
 * Deferrals page. Both copy the code and its reason from the centre's list
 * when written (deferral_codes).
 *
 * Null on rows written before codes existed: those keep only their typed
 * reason, which stays behind donors.view_clinical as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->string('deferral_code', 16)->nullable()->after('outcome');
        });

        Schema::table('donor_deferrals', function (Blueprint $table) {
            $table->string('reason_code', 16)->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropColumn('deferral_code');
        });

        Schema::table('donor_deferrals', function (Blueprint $table) {
            $table->dropColumn('reason_code');
        });
    }
};
