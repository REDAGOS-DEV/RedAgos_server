<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The role each pre-role department is migrated to.
     *
     * Hard-coded rather than read from StaffRole::defaultFor(): a migration
     * must describe the data as it stood when it was written, and an enum edited
     * later would silently change what this one does on a fresh install.
     *
     * Each is the least-privileged role that still does the department's core
     * work, so no account gains an ability it did not hold before.
     *
     * @var array<string, string>
     */
    private const DEFAULT_ROLE = [
        'collection' => 'phlebotomist',
        'testing' => 'serology_technologist',
        'processing' => 'component_technologist',
        'issuance' => 'inventory_control_officer',
        'billing' => 'billing_clerk',
    ];

    /**
     * Departments whose old abilities are now split across several roles.
     *
     * Staff migrated out of these keep only part of what they could do, so
     * they are named in the review warning.
     *
     * @var array<int, string>
     */
    private const SPLIT = ['collection', 'testing'];

    /**
     * Give every blood-centre staff member a role.
     *
     * users.department stays: it is now derived from the role by User's saving
     * hook, and kept so department-scoped queries and their index still work.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_role', 40)->nullable()->after('department');

            // Leading column is facility_id, so this serves the roster filtered
            // by role as well as the notification lookup by role.
            $table->index(['facility_id', 'staff_role']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['facility_id', 'staff_role']);
            $table->dropColumn('staff_role');
        });
    }

    /**
     * Assign each posted staff member their department's default role.
     *
     * Idempotent: only rows still without a role are touched, so a re-run never
     * overwrites a role a supervisor has since assigned. A supervisor carrying
     * a department is given its default role too — it decides where they land
     * after sign-in, and never narrows them, because the supervisor level holds
     * every ability anyway.
     *
     * Public so StaffRoleBackfillTest can drive it without rebuilding the
     * schema.
     */
    public function backfill(): void
    {
        foreach (self::DEFAULT_ROLE as $department => $role) {
            DB::table('users')
                ->whereNotNull('facility_id')
                ->where('department', $department)
                ->whereNull('staff_role')
                ->update(['staff_role' => $role]);
        }

        $this->warnAboutSplitDepartments();
    }

    /**
     * Name the staff who lost part of their reach, so a supervisor can reassign them.
     *
     * A Collection member used to register, screen and collect; as a
     * phlebotomist they now only collect. A Testing member used to record both
     * test sections; as a serology technologist they no longer type ABO/Rh.
     * Supervisors are left out: they still hold every ability.
     */
    private function warnAboutSplitDepartments(): void
    {
        $affected = DB::table('users')
            ->whereNotNull('facility_id')
            ->whereIn('department', self::SPLIT)
            ->where('is_supervisor', false)
            ->whereNull('deleted_at')
            ->orderBy('facility_id')
            ->orderBy('id')
            ->get(['id', 'facility_id', 'department', 'staff_role']);

        if ($affected->isEmpty()) {
            return;
        }

        $lines = $affected->map(
            fn (object $user): string => "facility {$user->facility_id}: user {$user->id} ({$user->department} → {$user->staff_role})"
        );

        Log::warning(
            'Staff roles backfilled. These accounts were in a department whose work is now split across several roles '
            .'and may need reassigning by a supervisor: '.$lines->implode('; ').'.'
        );
    }
};
