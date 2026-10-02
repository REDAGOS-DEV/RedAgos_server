<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A display grouping for the donor app, alongside the DOH section.
 *
 * Nullable so the v1 rows stay valid without a data migration; the client
 * falls back to the DOH sections when a version has no categories.
 * See App\Enums\QuestionCategory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_questions', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('section_number');
        });
    }

    public function down(): void
    {
        Schema::table('eligibility_questions', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
