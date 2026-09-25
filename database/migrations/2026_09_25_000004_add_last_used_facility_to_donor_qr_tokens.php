<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a check-in token was last presented, not just when.
 *
 * `last_used_at` records that a token was scanned; the questionnaire read needs
 * to know *where*. A facility may read a donor's health questionnaire when the
 * donor has presented there -- an appointment today, an open donation, an
 * existing donation relationship, or a QR verified at that counter today. This
 * column is what makes the fourth of those answerable.
 *
 * The alternative was a JSON-path predicate over audit_logs looking for
 * collection.qr_verified rows, which works but is an unindexed scan on a table
 * that only grows. One indexed column is cheaper, and "which centre last saw
 * this credential" is a useful audit fact on its own.
 *
 * nullOnDelete rather than cascade: a deleted facility must not take a donor's
 * live check-in token with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_qr_tokens', function (Blueprint $table) {
            $table->foreignId('last_used_facility_id')->nullable()->after('last_used_at')
                ->constrained('facilities')->cascadeOnUpdate()->nullOnDelete();

            $table->index(['donor_id', 'last_used_facility_id'], 'donor_qr_tokens_donor_facility_index');
        });
    }

    public function down(): void
    {
        Schema::table('donor_qr_tokens', function (Blueprint $table) {
            $table->dropIndex('donor_qr_tokens_donor_facility_index');
            $table->dropConstrainedForeignId('last_used_facility_id');
        });
    }
};
