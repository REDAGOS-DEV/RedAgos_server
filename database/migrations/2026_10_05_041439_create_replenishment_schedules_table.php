<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The days a hospital blood bank sends its weekly request to one blood centre.
     *
     * Set by the hospital itself, one row per centre it restocks from. The
     * days are ISO weekdays (1 = Monday … 7 = Sunday) as a JSON list rather
     * than seven boolean columns: the only question ever asked of them is
     * "is today one of them", which a list answers directly.
     */
    public function up(): void
    {
        Schema::create('replenishment_schedules', function (Blueprint $table): void {
            $table->id();

            // The hospital blood bank that keeps the schedule.
            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            // The blood centre it sends its weekly request to.
            $table->foreignId('target_facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            $table->json('days_of_week');

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamps();

            $table->unique(['facility_id', 'target_facility_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_schedules');
    }
};
