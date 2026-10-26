<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A counselling referral's steps are pending, referred to a doctor and closed
 * (project owner, 2026-10-26): Testing only refers the donor to a doctor. A
 * referral left at the old "contacted" step goes back to pending, which is
 * where it stood in that sense; its contacted_at stays as the record of when.
 *
 * Data only: the column keeps accepting "contacted", so nothing older breaks
 * on a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('counselling_referrals')
            ->where('status', 'contacted')
            ->update(['status' => 'pending']);
    }

    public function down(): void
    {
        DB::table('counselling_referrals')
            ->where('status', 'pending')
            ->whereNotNull('contacted_at')
            ->update(['status' => 'contacted']);
    }
};
