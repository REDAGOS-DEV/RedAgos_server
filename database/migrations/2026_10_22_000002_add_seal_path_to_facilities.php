<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A facility's left-hand logo, for the Daily Blood Stock Inventory (project
 * owner, 2026-10-22).
 *
 * Kept like logo_path: a file on the private `local` disk, served by a
 * signed route and inlined into the PDF. Nullable: a facility that has not
 * uploaded one prints the Department of Health seal there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('seal_path', 255)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('seal_path');
        });
    }
};
