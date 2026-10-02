<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section I-D of the DOH questionnaire, beyond the vitals already here.
 *
 * The table already holds the four measurements the form asks for — body
 * weight, blood pressure, pulse rate and temperature. What it has no room for
 * is the examination findings printed beside them, and the four boxes in the
 * form's top margin that the officer fills while the donor sits down.
 *
 * ALL EIGHT ARE THE OFFICER'S. They are entered at the counter, after the
 * donor has been scanned in, and nothing on the donor side of the application
 * writes to them. The donor never sees these fields: the four margin boxes are
 * spoken answers the officer asks for in person and transcribes, and the four
 * findings are what the officer observed.
 *
 * On `meds` in particular: the donor already answered "Currently taking
 * medication?" in Section I-B, days earlier, in the app. This is not that
 * answer and must never be pre-filled from it. Two authors answering one
 * subject at two moments is exactly the split this table was created for — see
 * the docblock on the migration that created it — and the records have to be
 * able to disagree, because a disagreement is itself a finding.
 *
 * Free text, nullable throughout, for the two reasons this table already
 * states: no document defines a vocabulary for any of them, and a partial
 * record is more honest than a mandatory field holding a placeholder.
 *
 * The form's remaining margin boxes, DH and DS, are not captured. Nothing
 * names what they abbreviate, and adding them later is additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            // Asked in person, before anything is measured.
            //
            // Named after the form's own labels rather than interpreted:
            // "Sleep:" is a ruled blank, so `sleep` and not `sleep_hours`.
            // Presuming it holds a number is the same kind of invention as
            // presuming a vocabulary.
            $table->string('sleep', 255)->nullable()->after('deferral_reason');
            $table->string('meal', 255)->nullable()->after('sleep');
            $table->string('meds', 255)->nullable()->after('meal');
            $table->string('allergies', 255)->nullable()->after('meds');

            // Observed. Printed on the form in this order.
            $table->string('general_appearance', 255)->nullable()->after('haemoglobin_g_dl');
            $table->string('skin', 255)->nullable()->after('general_appearance');
            $table->string('heent', 255)->nullable()->after('skin');
            $table->string('heart_and_lungs', 255)->nullable()->after('heent');
        });

        // No indexes: none of these is ever a query predicate. They are read as
        // part of one donation's record and never filtered on.
    }

    public function down(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropColumn([
                'sleep',
                'meal',
                'meds',
                'allergies',
                'general_appearance',
                'skin',
                'heent',
                'heart_and_lungs',
            ]);
        });
    }
};
