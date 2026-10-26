<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who handed the donor the counter's device, for a questionnaire taken onsite.
 *
 * Null for every questionnaire the donor answered in the Donor Portal, which is
 * how the counter and the printed form tell the two apart. A walk-in registered
 * at the counter has no sign-in, so the counter is the only place they can
 * answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_screenings', function (Blueprint $table) {
            $table->foreignId('recorded_by')
                ->nullable()
                ->after('consent_text_hash')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eligibility_screenings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
        });
    }
};
