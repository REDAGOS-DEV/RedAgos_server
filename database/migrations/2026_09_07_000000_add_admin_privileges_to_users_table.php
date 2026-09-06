<?php

use App\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give platform admin accounts a privilege set instead of all-or-nothing access.
     *
     * Until now `role:admin` was the only guard on every /admin route, so every
     * admin account could do everything: an account created to work the donor
     * ID queue could equally approve facilities and mint further admins. These
     * two columns are what let the routes narrow to `can:` checks.
     *
     * They are orthogonal in the same way department and is_supervisor are.
     * admin_privileges says which capabilities were granted; is_super_admin
     * says the account is unrestricted and ignores the list entirely.
     * Unrestricted is a flag rather than "a list that happens to be complete"
     * so that a privilege added to the catalogue later widens existing super
     * admins instead of silently leaving them behind.
     *
     * admin_privileges stays nullable because it is meaningless for donors and
     * blood-centre staff, which is the overwhelming majority of the table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false);
            $table->json('admin_privileges')->nullable();
        });

        $this->backfillExistingAdmins();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'admin_privileges']);
        });
    }

    /**
     * Promote every existing admin to unrestricted.
     *
     * Without this the migration is a lockout: the new columns default to false
     * and null, the routes below start demanding `can:`, and every admin that
     * existed a moment earlier — including the only account able to create a
     * replacement — holds nothing and can reach none of the portal.
     *
     * Promoting rather than granting the full list is deliberate: these
     * accounts genuinely were unrestricted before this migration ran, so the
     * flag records what was already true rather than inventing a grant.
     *
     * Public so AdminPrivilegeBackfillTest can drive it directly.
     */
    public function backfillExistingAdmins(): void
    {
        $adminRoleId = DB::table('roles')->where('name', RoleName::Admin->value)->value('id');

        // A fresh database migrated before its role seeder has no admin role
        // and therefore no admins to strand.
        if ($adminRoleId === null) {
            return;
        }

        $adminIds = DB::table('role_user')
            ->where('role_id', $adminRoleId)
            ->pluck('user_id')
            ->all();

        if ($adminIds === []) {
            return;
        }

        // Soft-deleted admins are included on purpose: restoring one later must
        // bring back the access it had, and a restore does not re-run this.
        DB::table('users')
            ->whereIn('id', $adminIds)
            ->update(['is_super_admin' => true]);
    }
};
