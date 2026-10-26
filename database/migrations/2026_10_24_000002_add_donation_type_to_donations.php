<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The type of donation the counter picks when it opens one (DonationType):
 * a walk-in, or for a patient, type-specific (TS) or not (NTS). The collection
 * record's TYPE OF DONOR counts it. Earlier donations were walk-ins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('donation_type', 20)->default('walk_in')->after('paper_sheet_id');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn('donation_type');
        });
    }
};
