<?php

use App\Enums\MarkerResult;
use App\Enums\SerologyMarker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reactive serology result waits for the Laboratory Supervisor's
 * verification before it takes effect, and the donor is asked to get in touch
 * by hand from the referral list (project owner, 2026-10-18).
 *
 * `verified_by` / `verified_at` on donation_serology: null on a reactive panel
 * means it is waiting. A non-reactive panel needs no verification and keeps
 * them null.
 *
 * `contact_requested_at` / `_by` on counselling_referrals: when the donor was
 * last asked to contact the centre, and by whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_serology', function (Blueprint $table) {
            $table->foreignId('verified_by')->nullable()->after('recorded_at')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->dateTime('verified_at')->nullable()->after('verified_by');
        });

        // Every reactive panel saved before this took effect as it was saved:
        // it is verified by its recorder, at the time it was recorded.
        DB::table('donation_serology')
            ->where(function (Builder $any): void {
                foreach (SerologyMarker::cases() as $marker) {
                    $any->orWhere($marker->value, MarkerResult::Reactive->value);
                }
            })
            ->update([
                'verified_by' => DB::raw('recorded_by'),
                'verified_at' => DB::raw('recorded_at'),
            ]);

        Schema::table('counselling_referrals', function (Blueprint $table) {
            $table->dateTime('contact_requested_at')->nullable()->after('closed_at');
            $table->foreignId('contact_requested_by')->nullable()->after('contact_requested_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });

        // Until now the request went out by itself as the referral opened.
        DB::table('counselling_referrals')->update(['contact_requested_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('counselling_referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_requested_by');
            $table->dropColumn('contact_requested_at');
        });

        Schema::table('donation_serology', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
    }
};
