<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record what the laboratory was told about a donation, and what it yielded.
     *
     * Two tables, because they answer two different questions and arrive at
     * different moments:
     *
     *  - donation_test_results: the screening outcome a qualified professional
     *    produced, plus the blood type they typed. One row per donation.
     *  - donation_components: which components the donation was separated into
     *    and how many bags of each. This is the declaration inventory intake is
     *    constrained to, so a unit cannot be recorded for a component the
     *    laboratory never produced.
     *
     * RedAgos does not perform testing. It records results provided by
     * qualified healthcare professionals — see the scope boundary in
     * docs/BLOOD-CENTER.md. Nothing here computes or infers a result.
     */
    public function up(): void
    {
        Schema::create('donation_test_results', function (Blueprint $table) {
            $table->id();

            // One result set per donation. A correction edits this row rather
            // than adding a second, so there is never an ambiguity about which
            // result cleared the blood.
            $table->foreignId('donation_id')->unique()
                ->constrained('donations')->cascadeOnUpdate()->restrictOnDelete();

            // The staff member who entered the result, not the professional who
            // produced it. Traceability for the record, not for the assay.
            $table->foreignId('recorded_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            // The type the laboratory read off this donation. Held separately
            // from the donor's profile so a mismatch between what the donor
            // believed and what the bag actually is becomes visible instead of
            // silently overwriting one with the other.
            $table->foreignId('blood_type_id')
                ->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();

            // passed      -> cleared for issue once components are declared
            // reactive    -> must be rejected; can never reach `completed`
            // inconclusive-> must be retested or rejected; also never completes
            $table->enum('result', ['passed', 'reactive', 'inconclusive']);

            $table->dateTime('tested_at');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['result', 'tested_at']);
            $table->index('recorded_by');
        });

        Schema::create('donation_components', function (Blueprint $table) {
            $table->id();

            $table->foreignId('donation_id')
                ->constrained('donations')->cascadeOnUpdate()->cascadeOnDelete();

            $table->foreignId('component_id')
                ->constrained('blood_components')->cascadeOnUpdate()->restrictOnDelete();

            // How many bags of this component the donation yielded. Inventory
            // may record up to this many units and no more.
            $table->unsignedSmallInteger('quantity');

            $table->foreignId('declared_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            $table->timestamps();

            // One row per component per donation; a correction updates the
            // quantity rather than adding a second row for the same component.
            $table->unique(['donation_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_components');
        Schema::dropIfExists('donation_test_results');
    }
};
