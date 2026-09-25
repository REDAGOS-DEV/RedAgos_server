<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section I-A of the DOH Blood Donor's Health Questionnaire.
 *
 * The split follows the one already in place: `users` holds account identity
 * (the name parts, email, phone), `donor_profiles` holds donor-domain
 * demographics (gender, birth date, address, valid ID).
 *
 * Every column is nullable with no default, because existing donors cannot be
 * back-filled. A default would write an assertion about them that nobody made
 * -- `nationality` defaulting to 'Filipino' is a statement about a real person,
 * not a convenience. The counter renders what is missing as "Not provided"
 * rather than as a blank that reads like an unanswered line on the paper form.
 *
 * Required-ness lives in RegisterDonorRequest, which only affects new accounts.
 *
 * Four I-A fields deliberately get no column here -- donor type, number of
 * times donated, and the date of last donation are all derived from donation
 * records. EligibilityRuleEvaluator's docblock already states the rule: those
 * come from the records, "never from the numbers typed into the questionnaire".
 * Storing a donor-declared copy would put two contradictory answers in one
 * payload. Only the venue of a last donation is non-derivable (it may have been
 * at a non-RedAgos centre), and that is a point-in-time declaration recorded on
 * the screening rather than a durable profile attribute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('middle_name', 150)->nullable()->after('first_name');
        });

        Schema::table('donor_profiles', function (Blueprint $table) {
            $table->string('civil_status', 20)->nullable()->after('birth_date');
            $table->string('occupation', 100)->nullable()->after('civil_status');
            $table->string('nationality', 60)->nullable()->after('occupation');

            // Sensitive personal information under RA 10173 s.3(l). Collected
            // because the form has a line for it, kept optional, and withheld
            // from every response that is not the questionnaire itself.
            $table->string('religion', 60)->nullable()->after('nationality');

            // The form asks the donor to tick which address they prefer post to
            // reach them at. The home address reuses the existing `address`.
            $table->string('preferred_mailing_address', 10)->nullable()->after('address');
            $table->string('office_address', 255)->nullable()->after('preferred_mailing_address');

            // Not unique, unlike users.phone. That column is unique because it
            // identifies an account; a shared household or office landline is
            // an ordinary case, not a duplicate donor.
            $table->string('telephone_no', 20)->nullable()->after('office_address');

            // Section I-C's "Contact Person (other relative/s)" block.
            $table->string('contact_person_name', 150)->nullable()->after('telephone_no');
            $table->string('contact_person_address', 255)->nullable()->after('contact_person_name');
            $table->string('contact_person_number', 20)->nullable()->after('contact_person_address');
        });

        // No indexes: none of these columns is ever a query predicate. They are
        // read as part of one donor's record and never filtered on.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('middle_name');
        });

        Schema::table('donor_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'civil_status',
                'occupation',
                'nationality',
                'religion',
                'preferred_mailing_address',
                'office_address',
                'telephone_no',
                'contact_person_name',
                'contact_person_address',
                'contact_person_number',
            ]);
        });
    }
};
