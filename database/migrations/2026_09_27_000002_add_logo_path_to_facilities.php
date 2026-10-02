<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A facility's own logo, for the right-hand side of the reports it prints.
 *
 * The path of a file on the private `local` disk, never a public URL: like
 * donor avatars, it is served through a short-lived signed route, and read
 * straight off the disk when a PDF is rendered (dompdf does not fetch URLs).
 * Nullable — a facility that has not uploaded one prints without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('logo_path', 255)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
