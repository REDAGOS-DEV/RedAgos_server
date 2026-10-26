<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A screening's deferral, lifted from the Deferrals page (project owner,
 * 2026-10-25: "it should still liftable"), as a deferral recorded there is.
 *
 * The screening keeps its outcome: it is the record of that visit's
 * decision. These say the deferral no longer stands, who lifted it and why,
 * and DonorDeferralRepository stops counting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->timestamp('lifted_at')->nullable()->after('deferred_until');
            $table->foreignId('lifted_by')->nullable()->after('lifted_at')->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable()->after('lifted_by');
        });
    }

    public function down(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lifted_by');
            $table->dropColumn(['lifted_at', 'lift_reason']);
        });
    }
};
