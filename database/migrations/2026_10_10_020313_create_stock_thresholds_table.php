<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One facility's own minimum stock for a blood type and component.
 *
 * Serves blood centres and hospital blood banks alike: the row says only
 * "this facility wants at least N units of O+ Packed RBC on its shelf", and the
 * facility's type decides whose shelf is counted. The count itself is never
 * stored here. Stock is derived from the units, so a threshold row holding a
 * quantity would be a second source of truth for it.
 *
 * `alerted_at` marks a low episode that has already been announced: set by the
 * sweep in the same transaction as the notifications it sent, cleared when the
 * cell stops being breached. It is what keeps a cell that stays low from
 * notifying again every minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_thresholds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('blood_type_id')->constrained('blood_types');
            $table->foreignId('component_id')->constrained('blood_components')->cascadeOnDelete();

            $table->unsignedSmallInteger('minimum_units');

            // Switches notifications off for one cell. The grid and the banner
            // still show its true status: muting an alert must not hide a
            // shortage from anyone looking at the screen.
            $table->boolean('alerts_enabled')->default(true);

            $table->timestamp('alerted_at')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One minimum per facility per blood type and component. The
            // screen upserts, so a second save corrects the first.
            $table->unique(['facility_id', 'blood_type_id', 'component_id'], 'stock_thresholds_cell_unique');

            // The sweep asks "which facilities have anything to watch".
            $table->index(['alerts_enabled', 'alerted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_thresholds');
    }
};
