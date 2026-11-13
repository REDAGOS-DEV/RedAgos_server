<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A blood centre's abbreviation: the first part of every bag number it books,
 * SNBC-21627990-PRBC (project owner, 2026-11-13).
 *
 * Nullable because a hospital blood bank has none. A blood centre is required
 * to have one by the requests that create and edit it, and BagNumbers numbers
 * no bag of a centre without one. Unique across soft-deleted facilities too: a
 * retired centre's bags still carry its abbreviation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('abbreviation', 6)->nullable()->unique()->after('name');
        });

        // Stored the way it prints: 2 to 6 capital letters.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_abbreviation_format CHECK (abbreviation IS NULL OR abbreviation ~ '^[A-Z]{2,6}$')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE facilities DROP CONSTRAINT IF EXISTS facilities_abbreviation_format');
        }

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropUnique(['abbreviation']);
            $table->dropColumn('abbreviation');
        });
    }
};
