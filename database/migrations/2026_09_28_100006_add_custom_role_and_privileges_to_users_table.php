<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a staff member hold a typed role, and cap any role with privileges.
     *
     * custom_role is the title typed in the staff form when none of the
     * predefined roles fits; such an account is placed in a department
     * directly and holds that department's abilities. staff_privileges is the
     * ticked subset of read / write / update / delete that caps whatever role
     * the account holds. Null means all four — the state of every account
     * created before privileges existed, so none of them loses access.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('custom_role', 100)->nullable()->after('staff_role');
            $table->json('staff_privileges')->nullable()->after('custom_role');
        });

        // Roles withdrawn when the staff form was cut to five departments.
        // No account should hold one, but if one does it is left role-less —
        // fail-closed — rather than crashing the enum cast.
        DB::table('users')
            ->whereIn('staff_role', [
                'recruitment_officer', 'pr_specialist', 'drive_logistics_coordinator',
                'bloodbank_technologist', 'crossmatch_technician', 'reference_lab_consultant',
            ])
            ->update(['staff_role' => null, 'department' => null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['custom_role', 'staff_privileges']);
        });
    }
};
