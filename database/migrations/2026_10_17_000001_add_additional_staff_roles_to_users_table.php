<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member may hold several roles (project owner, 2026-10-17).
 *
 * `staff_role` stays the main role: it decides the department, where the
 * person lands after signing in, and every existing reading of it keeps
 * working. This column holds the others, as StaffRole values. Every role must
 * be in the main role's department (the owner's rule), which User's saving
 * hook enforces.
 *
 * JSON rather than a pivot table: the list is short, read with its user, and
 * queried only as "does anyone here hold role X", which both PostgreSQL and
 * SQLite answer with whereJsonContains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('additional_staff_roles')->nullable()->after('staff_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('additional_staff_roles');
        });
    }
};
