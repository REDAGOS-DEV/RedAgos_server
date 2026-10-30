<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let blood from outside RedAgos be received without naming its blood service.
     *
     * A bag added on the hospital's Stock Intake records no source (project
     * owner, 2026-10-30). The unique index over source and number stays for the
     * receipts that name one; without a source, DirectDistributionService
     * refuses a number the blood bank already holds.
     */
    public function up(): void
    {
        Schema::table('direct_distributions', function (Blueprint $table): void {
            $table->unsignedBigInteger('external_blood_source_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('direct_distributions', function (Blueprint $table): void {
            $table->unsignedBigInteger('external_blood_source_id')->nullable(false)->change();
        });
    }
};
