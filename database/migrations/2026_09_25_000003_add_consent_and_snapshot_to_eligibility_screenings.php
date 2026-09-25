<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section I-C consent, plus the facts a screening must freeze at the moment it
 * is answered.
 *
 * Consent lives here rather than in its own table because it is 1:1 with a
 * submission and part of the same atomic act: ticking the statements is a
 * precondition of submitting. A separate table would permit a screening with no
 * consent row and a consent row with no screening, neither of which the
 * workflow has. This table is already the point-in-time snapshot of the act --
 * age_at_screening and weight_kg are the same kind of fact.
 *
 * Nullable is mandatory regardless, because screenings recorded before this
 * migration have no consent. A null column reads honestly as "not captured";
 * a missing join row reads as a failed lookup. The counter must render that
 * gap as an explicit negative and never as a blank date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_screenings', function (Blueprint $table) {
            $table->dateTime('consented_at')->nullable()->after('deferral_reasons');
            $table->string('consent_version', 20)->nullable()->after('consented_at');

            // SHA-256 of the exact statements the donor was shown. The version
            // key alone is not enough: config files get edited without anyone
            // bumping the version, and this proves which revision was on screen.
            $table->char('consent_text_hash', 64)->nullable()->after('consent_version');

            // Snapshot, for the same reason age_at_screening is one. Question 5
            // is asked only of female donors, so a later profile edit would
            // otherwise retroactively change what this record says was asked.
            $table->string('gender_at_screening', 20)->nullable()->after('age_at_screening');

            // The form's "Last menstrual period" line, which sits inside the
            // female-donors section as a free field rather than a yes/no.
            $table->date('last_menstrual_period')->nullable()->after('gender_at_screening');

            // The one Section I-A field that cannot be derived from records:
            // a previous donation may have been at a non-RedAgos centre. A
            // declaration about a past event, so it belongs to the submission
            // that declared it, not to the donor's profile.
            $table->string('declared_last_donation_venue', 150)->nullable()
                ->after('declared_last_donation_date');
        });
    }

    public function down(): void
    {
        Schema::table('eligibility_screenings', function (Blueprint $table) {
            $table->dropColumn([
                'consented_at',
                'consent_version',
                'consent_text_hash',
                'gender_at_screening',
                'last_menstrual_period',
                'declared_last_donation_venue',
            ]);
        });
    }
};
