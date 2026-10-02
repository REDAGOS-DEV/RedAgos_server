<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A patient's blood requirement, as the hospital blood bank records it.
     *
     * The patient's need is the record; the blood centres it is asked of are
     * not. A hospital short of O+ packed cells for a patient needs five units,
     * and may ask one centre for one, another for three and a third for one.
     * Each of those is a facility allocation — an ordinary blood_requests row
     * addressed to one centre, which approves or rejects it on its own. This
     * row holds the five, and what has come of asking for them is derived from
     * its allocations rather than stored here.
     *
     * Patient Transfusion only. A replenishment order restocks the hospital's
     * own shelves from one partner centre and stays a single blood_requests
     * row.
     *
     * Patient columns are nullable only so that requests raised before this
     * table existed can be moved under it; every new request is required to
     * name its patient by StoreTransfusionRequestRequest.
     *
     * Values hard-coded rather than read from the enums, so a later change to
     * an enum cannot change what this migration did.
     */
    public function up(): void
    {
        Schema::create('transfusion_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 30)->unique();

            // The hospital blood bank the patient belongs to: the requester.
            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            // Null on a walk-in, which nobody at the hospital submitted.
            $table->foreignId('requested_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            // The blood-centre staff member who entered a walk-in.
            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->enum('request_source', ['blood_bank_portal', 'blood_center_walk_in'])
                ->default('blood_bank_portal');

            $table->string('patient_surname', 100)->nullable();
            $table->string('patient_first_name', 100)->nullable();
            $table->string('patient_middle_name', 100)->nullable();
            $table->unsignedSmallInteger('patient_age')->nullable();
            $table->enum('patient_sex', ['male', 'female'])->nullable();

            $table->foreignId('blood_type_id')
                ->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('urgency_level', ['routine', 'emergency'])->default('routine');

            // Derived by TransfusionRequestResolver from the allocations, never
            // set by hand — except cancelled, which is the hospital's decision.
            $table->enum('status', ['pending', 'processing', 'partial', 'fulfilled', 'cancelled'])
                ->default('pending');

            // When staff confirmed their own blood bank could not cover the
            // patient. RedAgos does not hold a hospital's stock, so this is the
            // record that step 2 of the workflow happened. Null on a walk-in.
            $table->timestamp('internal_stock_checked_at')->nullable();

            $table->dateTime('request_date')->useCurrent();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['facility_id', 'status']);
            $table->index(['facility_id', 'patient_surname', 'patient_first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfusion_requests');
    }
};
