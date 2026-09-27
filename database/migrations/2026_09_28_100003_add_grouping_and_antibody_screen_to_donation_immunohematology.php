<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record forward and reverse grouping and the antibody screen separately.
     *
     * The combined blood_type_id stays the typing the rest of the system reads.
     * These three are what decide whether Immunohematology can vouch for it:
     * the clearance token is issued only when forward and reverse agree and the
     * antibody screen is negative.
     *
     * Nullable, because typings recorded before this existed carry none of
     * them. Those were cleared (or not) by the backfill and are not re-judged.
     */
    public function up(): void
    {
        Schema::table('donation_immunohematology', function (Blueprint $table) {
            $table->string('forward_group', 2)->nullable()->after('blood_type_id');
            $table->string('reverse_group', 2)->nullable()->after('forward_group');
            $table->string('antibody_screen', 10)->nullable()->after('reverse_group');
        });
    }

    public function down(): void
    {
        Schema::table('donation_immunohematology', function (Blueprint $table) {
            $table->dropColumn(['forward_group', 'reverse_group', 'antibody_screen']);
        });
    }
};
