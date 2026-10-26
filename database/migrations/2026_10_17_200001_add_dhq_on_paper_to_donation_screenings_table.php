<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The onsite health questionnaire is optional (project owner, 2026-10-17): a
 * donor may answer the printed DOH form instead, handed to them at the desk.
 *
 * Nothing of a paper form reaches RedAgos, so the screening records that one
 * was used. Without it, a donor accepted with no questionnaire on file reads
 * as one accepted without being asked anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->boolean('dhq_on_paper')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('donation_screenings', function (Blueprint $table) {
            $table->dropColumn('dhq_on_paper');
        });
    }
};
