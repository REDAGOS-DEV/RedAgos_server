<?php

use App\Enums\ScreeningOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record what the facility found when the donor presented at the counter.
     *
     * This is the on-site screening and pre-donation assessment the Capstone
     * BPMN puts between arrival and collection: "The donor then undergoes
     * screening and physical examination to determine eligibility for donation
     * ... Screening results are recorded, validated, and saved within the system
     * before blood data is officially recorded."
     *
     * Deliberately separate from `eligibility_screenings`, which is the donor's
     * own questionnaire, self-declared days earlier and scored by the server.
     * That one is what the donor claimed; this one is what a qualified
     * professional found. Mixing them would put two very different kinds of
     * claim, by two different authors, in one table.
     *
     * SCOPE BOUNDARY: RedAgos does not screen anyone. The vitals here are
     * transcribed from what authorized personnel measured and reported, and
     * `outcome` is their verdict — nothing in the application computes it or
     * checks the vitals against a threshold. See docs/BLOOD-CENTER.md and the
     * clinical-configuration boundary in docs/IMPLEMENTATION_DECISIONS.md.
     */
    public function up(): void
    {
        Schema::create('donation_screenings', function (Blueprint $table) {
            $table->id();

            // One screening per donation. A correction edits this row rather
            // than adding a second, so there is never an ambiguity about which
            // assessment let the donor proceed. Same rule as
            // donation_test_results.
            $table->foreignId('donation_id')->unique()
                ->constrained('donations')->cascadeOnUpdate()->restrictOnDelete();

            $table->foreignId('facility_id')
                ->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();

            // The staff member who entered the record, not the professional who
            // performed the examination. Traceability for the record.
            $table->foreignId('recorded_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            $table->enum('outcome', ScreeningOutcome::values());

            // Free text, because no document defines a deferral vocabulary and
            // inventing one would bake in clinical rules nobody has owned.
            $table->string('deferral_reason', 255)->nullable();

            // Every vital is nullable: what a centre measures varies, and a
            // partial record is more honest than a mandatory field filled with
            // a placeholder. No range is enforced here beyond what the column
            // can physically hold.
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedSmallInteger('pulse_bpm')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->decimal('haemoglobin_g_dl', 4, 1)->nullable();

            $table->string('notes', 500)->nullable();
            $table->dateTime('screened_at');
            $table->timestamps();

            $table->index(['facility_id', 'screened_at']);
            $table->index('recorded_by');
            $table->index(['outcome', 'screened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_screenings');
    }
};
