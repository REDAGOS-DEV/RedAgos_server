<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the donor was told that blood from this donation was given to a
     * patient (project owner, 2026-11-26).
     *
     * One donation is split into several bags that may be transfused weeks
     * apart, at different hospitals; the donor is told once, on the first.
     * The transfusion claims the donation by filling this column, so two bags
     * transfused at the same moment still send one notice. Left empty for
     * donations transfused before this, so a later bag of the same donation
     * still thanks the donor.
     */
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->timestamp('donor_told_transfused_at')->nullable()->after('labelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->dropColumn('donor_told_transfused_at');
        });
    }
};
