<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A blood-centre staff member's title: professional credentials such as RMT,
 * MD or RN (project owner, 2026-10-22).
 *
 * Printed after the name wherever any department says who did something
 * ("ROWENA TAN, RMT"), the stock report's "BY:" line included. It replaces
 * the position, which blood-centre accounts no longer keep. Nullable: most
 * accounts have none.
 */
return new class extends Migration
{
    /**
     * Titles that are credentials, which the Staff page suggested for the
     * position field before credentials had their own.
     */
    private const CREDENTIALS = ['RMT', 'RN', 'MD', 'RPh', 'RM'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('credentials', 40)->nullable()->after('position');
        });

        // A blood-centre account whose position is only a credential keeps it
        // as its title. Hospital staff still use their position.
        DB::table('users')
            ->whereNotNull('facility_id')
            ->whereNull('hospital_role')
            ->whereIn('position', self::CREDENTIALS)
            ->update(['credentials' => DB::raw('position'), 'position' => null]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereNotNull('facility_id')
            ->whereNull('hospital_role')
            ->whereNull('position')
            ->whereIn('credentials', self::CREDENTIALS)
            ->update(['position' => DB::raw('credentials')]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credentials');
        });
    }
};
