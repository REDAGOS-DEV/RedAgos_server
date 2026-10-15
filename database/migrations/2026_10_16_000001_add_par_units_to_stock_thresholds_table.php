<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A PAR level beside the critical level.
     *
     * The Stock Thresholds page sets two levels per blood type and component
     * (project owner, 2026-10-16): PAR, the stock the facility aims to keep,
     * and the critical level, the existing `minimum_units` that a low-stock
     * alert is judged against. Each is saved on its own, so a cell may hold a
     * PAR level before it has a critical level. `minimum_units` becomes
     * nullable for that; a row with neither level is still deleted, never kept.
     *
     * PAR is informational only. Nothing alerts on it.
     */
    public function up(): void
    {
        Schema::table('stock_thresholds', function (Blueprint $table) {
            $table->unsignedSmallInteger('minimum_units')->nullable()->change();
            $table->unsignedSmallInteger('par_units')->nullable()->after('minimum_units');
        });
    }

    /**
     * Rows that only hold a PAR level have nothing left to keep, so they go.
     */
    public function down(): void
    {
        DB::table('stock_thresholds')->whereNull('minimum_units')->delete();

        Schema::table('stock_thresholds', function (Blueprint $table) {
            $table->dropColumn('par_units');
            $table->unsignedSmallInteger('minimum_units')->nullable(false)->change();
        });
    }
};
