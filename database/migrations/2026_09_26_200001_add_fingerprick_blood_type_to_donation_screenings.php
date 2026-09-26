<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fingerprick blood type read at the screening table.
 *
 * Section II of the DOH form prints a small "Hemoglobin / Blood Type" table
 * beside the phlebotomist's box. Both readings are taken from a fingerprick
 * before the donor is bled, which makes them part of screening: haemoglobin
 * already lives here as `haemoglobin_g_dl`, and this is its partner.
 *
 * PRELIMINARY, AND NEVER ADOPTED. A capillary slide typing is not the
 * laboratory's confirmatory ABO/Rh on the unit's sample. It is never copied
 * onto the donor profile and never pre-fills the laboratory's typing — only
 * the Testing department's immunohematology result does either. The two are
 * kept apart so a disagreement between them shows up instead of one silently
 * overwriting the other.
 *
 * Nullable: a centre that does not type at the screening table records nothing,
 * and every screening before this column existed has no reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->foreignId('fingerprick_blood_type_id')->nullable()->after('haemoglobin_g_dl')
                ->constrained('blood_types')->cascadeOnUpdate()->restrictOnDelete();
        });

        // No index beyond the foreign key's own: it is never a query predicate.
    }

    public function down(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fingerprick_blood_type_id');
        });
    }
};
