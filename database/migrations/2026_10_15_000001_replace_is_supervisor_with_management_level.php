<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Split the supervisor flag into a Blood Center Admin and a view-only Supervisor.
 *
 * `is_supervisor` was the Center Admin: it held every ability in the portal,
 * department work included. The project owner split it (2026-10-15) into
 * `users.management_level`, App\Enums\ManagementLevel:
 *
 *  - `admin`: staff accounts, roles and privileges, the centre's settings and
 *    escalated correction decisions, but no department work;
 *  - `supervisor`: the dashboard, reports and view-only monitoring.
 *
 * Every existing supervisor becomes an Admin, because what they were trusted
 * with is running the centre's accounts, and the staff floor (a facility must
 * keep someone able to manage its staff) has to hold on the day this runs.
 * They lose the department work the old flag carried with it; an account that
 * also holds a staff role keeps that role's abilities.
 *
 * The old column is dropped rather than kept beside the new one. A check left
 * reading `is_supervisor` would otherwise go on granting the old everything
 * to someone who is now only meant to watch; with the column gone, a missed
 * check fails closed instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('management_level', 20)->nullable()->after('department');
        });

        // Soft-deleted accounts included, so a restored one comes back as it was.
        $promoted = DB::table('users')->where('is_supervisor', true)->update(['management_level' => 'admin']);

        Log::info("management_level: {$promoted} supervisor account(s) became Blood Center Admins.");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_supervisor');
        });
    }

    /**
     * An Admin goes back to the supervisor flag. A view-only Supervisor has no
     * equivalent before this migration, and is rolled back to no level at all,
     * never to the flag that would have handed them every ability.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_supervisor')->default(false)->after('department');
        });

        DB::table('users')->where('management_level', 'admin')->update(['is_supervisor' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('management_level');
        });
    }
};
