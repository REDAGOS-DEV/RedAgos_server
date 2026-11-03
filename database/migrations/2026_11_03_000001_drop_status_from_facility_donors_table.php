<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stop staff marking a donor Active or Inactive by hand.
     *
     * Active now means a donation in the last 12 months, worked out from the
     * donor's last donation (DonorStanding::activity(), project owner,
     * 2026-11-03), so there is nothing left to store. Down restores the
     * column with everyone active, as a donor with no row always was.
     */
    public function up(): void
    {
        Schema::table('facility_donors', function (Blueprint $table): void {
            $table->dropIndex(['facility_id', 'status']);
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('facility_donors', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->after('donor_id');
            $table->index(['facility_id', 'status']);
        });
    }
};
