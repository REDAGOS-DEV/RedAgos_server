<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hospital blood bank's scheduled restock, sent to one centre on one of its request days.
     *
     * A header only. What was asked for lives on its blood requests, one per
     * blood type, each an ordinary replenishment request the centre works as
     * it always has. A weekly request carries no quantities of its own, so
     * there is nothing here to drift from them.
     *
     * The unique (facility, centre, day) index is what holds "one weekly
     * request per centre per request day" when two members of staff submit at
     * once; the service's own check only gives the friendlier error.
     */
    public function up(): void
    {
        Schema::create('weekly_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 30)->unique();

            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('target_facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            // The operational date it was sent for, in the blood centre's timezone.
            $table->date('request_day');

            $table->foreignId('requested_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamps();

            $table->unique(['facility_id', 'target_facility_id', 'request_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_requests');
    }
};
