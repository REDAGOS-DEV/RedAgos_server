<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record why a donation was rejected, from either department.
     *
     * Until now `donations.status = rejected` carried no reason, so a donor
     * deferred at the counter and a bag that came back reactive were
     * indistinguishable in the record. Both are recorded outcomes a member of
     * staff is accountable for, and the donor has to be told which one happened.
     * Logged as UNRESOLVED twice in docs/IMPLEMENTATION_DECISIONS.md.
     *
     * Nullable, because a rejection recorded before this column existed has no
     * reason to backfill and inventing one would be worse than leaving it blank.
     */
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('rejection_reason', 255)->nullable()->after('volume_ml');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
