<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Support\DepartmentPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StaffRoleBackfillTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Drive the migration's backfill directly, as SupervisorBackfillTest does.
     */
    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_28_000001_add_staff_role_to_users_table.php');

        $migration->backfill();
    }

    /**
     * Build staff as the migration finds them: a department, no role.
     *
     * Written through the query builder so User's saving hook, which derives
     * the department from the role, cannot rewrite the legacy shape.
     */
    private function legacyStaff(Facility $facility, string $department, bool $supervisor = false): User
    {
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        DB::table('users')->where('id', $staff->id)->update([
            'department' => $department,
            'staff_role' => null,
            'is_supervisor' => $supervisor,
        ]);

        return $staff;
    }

    public function test_each_department_gets_its_default_role(): void
    {
        $facility = Facility::factory()->approved()->create();

        $expected = [
            'collection' => StaffRole::Phlebotomist,
            'testing' => StaffRole::SerologyTechnologist,
            'processing' => StaffRole::ComponentTechnologist,
            'issuance' => StaffRole::InventoryControlOfficer,
            'billing' => StaffRole::BillingClerk,
        ];

        $staff = [];

        foreach (array_keys($expected) as $department) {
            $staff[$department] = $this->legacyStaff($facility, $department);
        }

        $this->runBackfill();

        foreach ($expected as $department => $role) {
            $fresh = $staff[$department]->fresh();

            $this->assertSame($role, $fresh->staff_role, $department);
            $this->assertSame($department, $fresh->department->value, 'The department must be unchanged.');
        }
    }

    public function test_no_backfilled_role_gains_an_ability_its_department_lacked(): void
    {
        // Pinned against the pre-role matrix, written out here so the check
        // does not depend on the file it is checking. Issuance is allowed two
        // new abilities: releasing from quarantine replaced the implicit
        // clearance Processing used to give by completing, and recording a
        // walk-in request is new work the counter took on, not a widening of
        // anything it held.
        $old = [
            'collection' => ['donors.view', 'donors.manage', 'donors.view_questionnaire', 'appointments.view', 'appointments.verify', 'drives.view', 'drives.manage', 'donations.view', 'donations.record', 'inventory.view'],
            'testing' => ['lab.view', 'lab.record_result', 'lab.referrals', 'donations.view', 'inventory.view'],
            'processing' => ['lab.view', 'lab.record_components', 'lab.update_status', 'donations.view', 'inventory.view'],
            'issuance' => ['inventory.view', 'inventory.create', 'inventory.update', 'inventory.discard', 'requests.view', 'requests.process', 'requests.approve', 'requests.release', 'billing.view', 'donations.view'],
            'billing' => ['billing.view', 'billing.create', 'billing.record_payment', 'requests.view'],
        ];

        // The split abilities count as held when their parent was.
        $parents = [
            'donations.collect' => 'donations.record',
            'donations.close' => 'donations.record',
            'lab.record_serology' => 'lab.record_result',
            'lab.record_immunohematology' => 'lab.record_result',
            // Identity was always part of what donors.view showed the counter.
            'donors.view_identity' => 'donors.view',
        ];

        foreach ($old as $department => $held) {
            $role = StaffRole::defaultFor(Department::from($department));

            foreach (DepartmentPermissions::forRole($role) as $ability) {
                if (in_array($ability, ['reference.view', 'reports.view_own', 'inventory.release_quarantine', 'requests.record', 'corrections.request', 'corrections.approve'], true)) {
                    continue;
                }

                $this->assertContains(
                    $parents[$ability] ?? $ability,
                    $held,
                    "{$department} → {$role->value} gained [{$ability}]."
                );
            }
        }
    }

    public function test_a_role_already_assigned_is_never_overwritten(): void
    {
        $facility = Facility::factory()->approved()->create();
        $physician = User::factory()->bloodCenterStaff($facility, StaffRole::ScreeningPhysician)->create();

        $this->runBackfill();

        $this->assertSame(StaffRole::ScreeningPhysician, $physician->fresh()->staff_role);
    }

    public function test_a_supervisor_with_a_department_gets_its_role_and_one_without_stays_unassigned(): void
    {
        $facility = Facility::factory()->approved()->create();
        $working = $this->legacyStaff($facility, 'testing', supervisor: true);
        $management = User::factory()->bloodCenterSupervisor($facility)->create();

        $this->runBackfill();

        $this->assertSame(StaffRole::SerologyTechnologist, $working->fresh()->staff_role);
        $this->assertNull($management->fresh()->staff_role);
    }

    public function test_donors_are_left_alone(): void
    {
        $donor = User::factory()->donor()->create();

        $this->runBackfill();

        $this->assertNull($donor->fresh()->staff_role);
    }

    public function test_staff_in_a_split_department_are_named_for_review(): void
    {
        Log::spy();

        $facility = Facility::factory()->approved()->create();
        $counter = $this->legacyStaff($facility, 'collection');
        $this->legacyStaff($facility, 'issuance');

        $this->runBackfill();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message): bool => str_contains($message, "user {$counter->id} (collection → phlebotomist)")
                && ! str_contains($message, 'issuance')
        );
    }
}
