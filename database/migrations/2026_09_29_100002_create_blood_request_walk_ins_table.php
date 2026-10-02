<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a walk-in request carries beyond the request itself.
     *
     * One row per walk-in, never one per portal request, so the requests the
     * Blood Bank Portal raises are untouched by any of this.
     *
     * The watcher is recorded as the representative who presented the request,
     * never as the requester: the hospital blood bank remains the institutional
     * party, which is why the request's facility_id is still the hospital.
     *
     * Every verification column is NOT NULL. A walk-in is only ever created
     * after the hospital has confirmed it by phone, so a row without the name
     * of the person who confirmed it, and when, would be a request nobody
     * verified. A call the hospital does not confirm leaves no row at all.
     */
    public function up(): void
    {
        Schema::create('blood_request_walk_ins', function (Blueprint $table): void {
            $table->id();

            // cascadeOnDelete: this row is part of its request, not a record in
            // its own right, exactly like the request's lines.
            $table->foreignId('request_id')->unique()
                ->constrained('blood_requests')->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('representative_name', 150);
            $table->string('representative_relationship', 60);
            $table->string('representative_contact', 30);
            $table->string('representative_id_type', 30)->nullable();
            $table->string('representative_id_number', 50)->nullable();

            // What the watcher brings from the hospital. All optional: a
            // watcher may carry only the physician's signed request slip.
            $table->string('presented_reference', 60)->nullable();
            $table->string('attending_physician', 150)->nullable();
            $table->string('patient_ward', 100)->nullable();
            $table->string('patient_record_number', 60)->nullable();

            $table->string('verifier_name', 150);
            $table->string('verifier_position', 100);
            $table->string('verifier_contact', 30);
            $table->timestamp('verified_at');

            // restrictOnDelete, matching blood_requests.requested_by: this is
            // an accountability record for who took the hospital's word.
            $table->foreignId('verification_recorded_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('verification_notes', 500)->nullable();

            // Why staff went ahead despite an open request for the same patient.
            $table->string('duplicate_acknowledgement', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_request_walk_ins');
    }
};
