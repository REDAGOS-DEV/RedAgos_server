<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What version 2 of the questionnaire needs that version 1 did not.
 *
 * `section_title` exists because EligibilityService::questions() titles each
 * section with Str::headline($section_key), and no snake_case key produces
 * "In the past 12 months, have you". A legal form's heading is transcribed
 * data, not derived text. The column is nullable and the service falls back to
 * Str::headline() when it is null, so v1 behaves exactly as it does today.
 *
 * `section_number` makes section ordering explicit. Today sections happen to
 * come out in the right order because scopeForVersion() orders by question
 * number and groupBy() preserves first appearance -- true only while each
 * section's numbers are contiguous, which is an accident of v1 having two
 * sections rather than a guarantee.
 *
 * Every column is nullable so the v1 rows, which have screenings against them,
 * stay valid without a data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_questions', function (Blueprint $table) {
            $table->string('section_title', 150)->nullable()->after('section_key');
            $table->unsignedSmallInteger('section_number')->nullable()->after('section_title');

            // 'female' for the DOH form's question 5. Null means the question is
            // asked of everyone. Applicability is resolved server-side from the
            // donor's stored gender so the client cannot decide who is asked
            // what, and an inapplicable question is omitted from the payload
            // entirely rather than answered with a clinical falsehood.
            $table->string('applies_to_gender', 20)->nullable()->after('disqualify_if_answer');

            // 'risk' | 'acknowledgement'. Question 29 asks the donor to confirm
            // they understand something, which is not a risk answer and should
            // not be rendered among them.
            $table->string('kind', 20)->nullable()->after('applies_to_gender');
        });

        // The DOH wordings are long -- question 1 alone runs past 180
        // characters and question 29 past 150. varchar(500) holds them today,
        // but it was sized for v1's short questions and leaves no room for a
        // future revision that spells one out further.
        Schema::table('eligibility_questions', function (Blueprint $table) {
            $table->text('text')->change();
        });
    }

    public function down(): void
    {
        Schema::table('eligibility_questions', function (Blueprint $table) {
            $table->string('text', 500)->change();
        });

        Schema::table('eligibility_questions', function (Blueprint $table) {
            $table->dropColumn([
                'section_title',
                'section_number',
                'applies_to_gender',
                'kind',
            ]);
        });
    }
};
