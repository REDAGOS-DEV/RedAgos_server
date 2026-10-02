<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One facility's own shelf life and price for a blood component.
 *
 * `blood_components` is a single shared catalogue with no facility_id, so the
 * values on it are network-wide. That is wrong for both columns this table
 * overrides. Shelf life varies by preparation, anticoagulant and storage
 * protocol, so two centres can legitimately disagree; and price drives
 * BillingService, where a non-zero value switches on the payment-before-release
 * gate — which one centre must not be able to turn on for the other three.
 *
 * A missing row means "not configured here". Nothing is defaulted from the
 * shared catalogue's own columns, because those are seeded null and zero and a
 * fallback would quietly reintroduce the shared value this table exists to
 * avoid. Stock intake refuses a component with no shelf life rather than
 * guessing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_blood_components', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('blood_components')->cascadeOnDelete();

            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->decimal('price', 10, 2)->nullable();

            // Who last set a clinical value, for the audit that follows a
            // mis-dated unit back to the decision behind it.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per facility per component. The screen upserts, so a
            // second save corrects the first rather than adding a rival value.
            $table->unique(['facility_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_blood_components');
    }
};
