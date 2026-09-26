<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Testing department's two sections of Section II of the DOH form.
 *
 * IMMUNOHEMATOLOGY is the confirmatory ABO and Rh typing on the unit's sample.
 * SEROLOGY is the transfusion-transmissible-infection panel. The form prints
 * both as tables with a "Screened by" column, and they are separate benches:
 * two medical technologists can each record one, so each section is its own
 * row stamped with the staff member who saved it.
 *
 * WHY NOT MORE COLUMNS ON donation_test_results. That table stays exactly as
 * it is, as the derived summary: the Testing service writes it only once the
 * outcome is decided (both sections non-reactive, or any marker reactive).
 * Its NOT NULL `blood_type_id` and `result` would otherwise have had to be
 * relaxed on Postgres, and everything downstream — guardReadyToComplete,
 * adoptVerifiedBloodType, inventory intake — keeps reading the row it always
 * read. Legacy rows recorded before these tables existed stay valid.
 *
 * ONE blood_type_id FOR ABO AND Rh. The form prints them as two rows, but
 * `blood_types` already holds the combined codes (A+ … O-) that the donor
 * profile, the mismatch guard and every blood unit use. The Testing page
 * shows two pickers and resolves them to one row.
 *
 * FIVE MARKERS, AS COLUMNS. The panel is closed at exactly five — HIV, HBsAg,
 * HCV, Syphilis and Malaria — and is always saved whole, so a column per
 * marker is simpler to validate and read than a child table. NAT and "Others"
 * on the printed form are deliberately not recorded. Each marker is the
 * medical technologist's final reading: repeat testing happens at the bench,
 * so there is no initial/repeat pair and no per-marker "inconclusive".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_immunohematology', function (Blueprint $table) {
            $table->id();

            $table->foreignId('donation_id')->unique()
                ->constrained('donations')->cascadeOnUpdate()->restrictOnDelete();

            $table->foreignId('blood_type_id')
                ->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('notes', 500)->nullable();

            // "Screened by": the authenticated staff member who saved this
            // section, never a name from the request.
            $table->foreignId('recorded_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->dateTime('recorded_at');

            $table->timestamps();
        });

        Schema::create('donation_serology', function (Blueprint $table) {
            $table->id();

            $table->foreignId('donation_id')->unique()
                ->constrained('donations')->cascadeOnUpdate()->restrictOnDelete();

            $table->enum('hiv', ['reactive', 'non_reactive']);
            $table->enum('hbsag', ['reactive', 'non_reactive']);
            $table->enum('hcv', ['reactive', 'non_reactive']);
            $table->enum('syphilis', ['reactive', 'non_reactive']);
            $table->enum('malaria', ['reactive', 'non_reactive']);

            $table->foreignId('recorded_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->dateTime('recorded_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_serology');
        Schema::dropIfExists('donation_immunohematology');
    }
};
