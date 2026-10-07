<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Support\DepartmentPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DepartmentPermissionMatrixTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const MANAGEMENT = ['staff.manage', 'center.configure', 'reports.view_all'];

    public function test_every_declared_ability_is_registered_as_a_gate(): void
    {
        foreach (DepartmentPermissions::all() as $ability) {
            $this->assertTrue(
                Gate::has($ability),
                "Ability [{$ability}] is declared in the matrix but no gate was defined for it."
            );
        }
    }

    public function test_every_role_sits_in_exactly_one_department_and_every_department_has_a_role(): void
    {
        $seen = [];

        foreach (Department::cases() as $department) {
            $roles = $department->roles();

            $this->assertNotEmpty($roles, "{$department->value} has no roles to assign.");

            foreach ($roles as $role) {
                $this->assertSame($department, $role->department());
                $this->assertNotContains($role, $seen, "{$role->value} is listed under two departments.");
                $seen[] = $role;
            }
        }

        $this->assertCount(count(StaffRole::cases()), $seen, 'A role is missing from every department.');
    }

    public function test_each_role_holds_exactly_the_abilities_the_matrix_declares(): void
    {
        foreach (StaffRole::cases() as $role) {
            $staff = User::factory()->bloodCenterStaff(null, $role)->create();

            $this->assertEqualsCanonicalizing(
                DepartmentPermissions::forRole($role),
                $staff->abilities(),
                "{$role->value} resolved a different ability set than the matrix declares."
            );
        }
    }

    public function test_the_department_is_derived_from_the_role(): void
    {
        $staff = User::factory()->bloodCenterStaff(null, StaffRole::LabSupervisor)->create();

        $this->assertSame(Department::Testing, $staff->fresh()->department);

        // Assigning a department directly is overwritten on save: the role is
        // the only source of truth.
        $staff->department = Department::Billing;
        $staff->save();

        $this->assertSame(Department::Testing, $staff->fresh()->department);

        $staff->staff_role = StaffRole::DispatchCoordinator;
        $staff->save();

        $this->assertSame(Department::Issuance, $staff->fresh()->department);
    }

    public function test_the_staff_form_lists_five_departments(): void
    {
        $this->assertSame(
            ['Donor/Collection', 'Processing', 'Testing', 'Issuance', 'Billing'],
            array_map(fn (Department $department): string => $department->label(), Department::cases())
        );
    }

    // --- Privileges -----------------------------------------------------------

    public function test_every_ability_a_role_can_hold_has_a_privilege_kind(): void
    {
        foreach (DepartmentPermissions::all() as $ability) {
            if (in_array($ability, ['staff.manage', 'center.configure', 'reports.view_all'], true)) {
                continue;
            }

            $this->assertNotNull(
                DepartmentPermissions::kindOf($ability),
                "[{$ability}] has no privilege kind, so no Read/Write/Update/Delete tick could cap it."
            );
        }
    }

    public function test_privileges_cap_a_role_but_never_widen_it(): void
    {
        $readOnly = User::factory()->bloodCenterStaff(null, StaffRole::InventoryControlOfficer)
            ->withPrivileges(['read'])
            ->create();

        $abilities = $readOnly->abilities();

        $this->assertContains('inventory.view', $abilities);
        $this->assertContains('requests.view', $abilities);
        $this->assertNotContains('inventory.create', $abilities);
        $this->assertNotContains('requests.release', $abilities);
        $this->assertNotContains('inventory.discard', $abilities);

        // Ticking Delete gives the officer discard — which the role holds —
        // and nothing the role does not.
        $withDelete = User::factory()->bloodCenterStaff(null, StaffRole::ItDataClerk)
            ->withPrivileges(['read', 'delete'])
            ->create();

        $this->assertNotContains('inventory.discard', $withDelete->abilities());
    }

    public function test_an_unticked_privilege_is_refused_at_the_route(): void
    {
        $facility = Facility::factory()->approved()->create();
        $noDelete = User::factory()->bloodCenterStaff($facility, StaffRole::InventoryControlOfficer)
            ->withPrivileges(['read', 'write', 'update'])
            ->create();

        $this->actingAs($noDelete)->getJson('/api/blood-center/inventory')->assertOk();

        $this->actingAs($noDelete)
            ->postJson('/api/blood-center/inventory/RA1-1-01/discard', ['reason' => 'Damaged bag'])
            ->assertForbidden();
    }

    public function test_an_account_with_no_stored_privileges_keeps_its_whole_role(): void
    {
        $staff = User::factory()->bloodCenterStaff(null, StaffRole::InventoryControlOfficer)->create();

        $this->assertNull($staff->staff_privileges);
        $this->assertEqualsCanonicalizing(
            DepartmentPermissions::forRole(StaffRole::InventoryControlOfficer),
            $staff->abilities()
        );
    }

    public function test_a_supervisor_is_never_capped(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor()->withPrivileges(['read'])->create();

        $this->assertEqualsCanonicalizing(DepartmentPermissions::all(), $supervisor->abilities());
    }

    // --- Custom roles -----------------------------------------------------------

    public function test_a_custom_role_holds_its_departments_set_less_correction_approval(): void
    {
        $custom = User::factory()->bloodCenterCustomStaff(null, Department::Testing, 'Senior Medical Technologist')->create();

        $this->assertSame(Department::Testing, $custom->fresh()->department);
        $this->assertSame('Senior Medical Technologist', $custom->roleLabel());

        $abilities = $custom->abilities();

        $this->assertContains('lab.record_serology', $abilities);
        $this->assertContains('lab.record_immunohematology', $abilities);
        $this->assertNotContains('corrections.approve', $abilities);
        $this->assertNotContains('lab.record_components', $abilities);
    }

    public function test_a_custom_role_is_capped_by_privileges_too(): void
    {
        $custom = User::factory()->bloodCenterCustomStaff(null, Department::Issuance, 'Stock Auditor')
            ->withPrivileges(['read'])
            ->create();

        $this->assertContains('inventory.view', $custom->abilities());
        $this->assertNotContains('inventory.create', $custom->abilities());
    }

    public function test_no_role_holds_a_management_ability(): void
    {
        foreach (StaffRole::cases() as $role) {
            foreach (self::MANAGEMENT as $management) {
                $this->assertNotContains(
                    $management,
                    DepartmentPermissions::forRole($role),
                    "{$role->value} must not hold the management ability [{$management}]."
                );
            }
        }
    }

    public function test_a_supervisor_holds_every_ability_regardless_of_role(): void
    {
        $managementOnly = User::factory()->bloodCenterSupervisor()->create();
        $working = User::factory()->bloodCenterSupervisor(null, StaffRole::BillingClerk)->create();

        $this->assertEqualsCanonicalizing(DepartmentPermissions::all(), $managementOnly->abilities());

        // The role must not narrow the set: a supervisor working as a billing
        // clerk still holds inventory.create, which a billing clerk never does.
        $this->assertEqualsCanonicalizing(DepartmentPermissions::all(), $working->abilities());
        $this->assertContains('inventory.create', $working->abilities());
    }

    public function test_staff_without_a_role_hold_nothing(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->staff_role = null;
        $staff->save();

        $fresh = $staff->fresh();

        $this->assertNull($fresh->department);
        $this->assertSame([], $fresh->abilities());
    }

    public function test_a_role_less_account_cannot_reach_an_operational_endpoint(): void
    {
        // reference-data is shared by every role, which is exactly why it
        // needs an ability of its own — access role plus facility status alone
        // would wave an unassigned account straight through.
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->staff_role = null;
        $staff->save();

        $this->actingAs($staff->fresh())
            ->getJson('/api/blood-center/reference-data')
            ->assertForbidden();
    }

    public function test_billing_staff_cannot_write_inventory_but_may_read_billing(): void
    {
        $facility = Facility::factory()->approved()->create();
        $billing = User::factory()->bloodCenterStaff($facility, StaffRole::BillingClerk)->create();

        $this->actingAs($billing)
            ->postJson('/api/blood-center/inventory', [])
            ->assertForbidden();

        $this->assertContains('billing.record_payment', $billing->abilities());
        $this->assertNotContains('inventory.create', $billing->abilities());
    }

    public function test_processing_may_read_inventory_but_not_write_it(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility, StaffRole::ComponentTechnologist)->create();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/inventory')
            ->assertOk();

        $this->actingAs($staff)
            ->postJson('/api/blood-center/inventory', [])
            ->assertForbidden();
    }

    /**
     * Pinned by name rather than compared against the matrix: the matrix-derived
     * assertions above derive both sides from DepartmentPermissions and so
     * cannot notice a split being undone.
     */
    public function test_the_laboratory_writes_are_split_between_testing_and_processing(): void
    {
        $processing = DepartmentPermissions::forDepartment(Department::Processing);
        $testing = DepartmentPermissions::forDepartment(Department::Testing);

        foreach ([$processing, $testing] as $department) {
            $this->assertContains('lab.view', $department);
        }

        // Testing records both sections: the TTI panel and the typing.
        $this->assertContains('lab.record_serology', $testing);
        $this->assertContains('lab.record_immunohematology', $testing);
        $this->assertNotContains('lab.record_components', $testing);
        $this->assertNotContains('lab.update_status', $testing);

        $this->assertContains('lab.record_components', $processing);
        $this->assertContains('lab.update_status', $processing);
        $this->assertNotContains('lab.record_serology', $processing);
        $this->assertNotContains('lab.record_immunohematology', $processing);
    }

    public function test_only_the_component_technologist_completes_a_donation(): void
    {
        $this->assertSame(
            [StaffRole::ComponentTechnologist],
            DepartmentPermissions::rolesHolding('lab.update_status')
        );
    }

    /**
     * The referral list names which infection each donor carries. The
     * technologists who record markers are blind to donor identity, so the
     * list that puts a name to a result belongs to their supervisor alone.
     */
    public function test_only_the_lab_supervisor_holds_the_counselling_referral_list(): void
    {
        $this->assertSame([StaffRole::LabSupervisor], DepartmentPermissions::rolesHolding('lab.referrals'));
    }

    /**
     * Reading a donor's declared health answers and their clinical history
     * belongs to the physician who screens them, and to nobody else at the
     * counter.
     */
    public function test_only_the_screening_physician_reads_the_clinical_record(): void
    {
        foreach (['donors.view_questionnaire', 'donors.view_clinical', 'donations.screen'] as $ability) {
            $this->assertSame(
                [StaffRole::ScreeningPhysician],
                DepartmentPermissions::rolesHolding($ability),
                "Only the screening physician may hold [{$ability}]."
            );
        }
    }

    public function test_the_chair_cannot_screen_or_register(): void
    {
        foreach ([StaffRole::Phlebotomist, StaffRole::ApheresisSpecialist] as $role) {
            $abilities = DepartmentPermissions::forRole($role);

            $this->assertContains('donations.collect', $abilities);
            $this->assertNotContains('donations.screen', $abilities);
            $this->assertNotContains('donations.register', $abilities);
            $this->assertNotContains('donors.view_questionnaire', $abilities);
        }
    }

    public function test_the_receptionist_never_reaches_a_clinical_record(): void
    {
        $abilities = DepartmentPermissions::forRole(StaffRole::MedicalReceptionist);

        $this->assertContains('donations.register', $abilities);
        $this->assertContains('donors.manage', $abilities);

        foreach (['donors.view_clinical', 'donors.view_questionnaire', 'donations.screen', 'lab.view'] as $clinical) {
            $this->assertNotContains($clinical, $abilities);
        }
    }

    public function test_issuance_and_billing_hold_no_laboratory_ability(): void
    {
        foreach ([Department::Issuance, Department::Billing] as $department) {
            foreach (DepartmentPermissions::forDepartment($department) as $ability) {
                $this->assertStringStartsNotWith('lab.', $ability, "{$department->value} holds [{$ability}].");
            }
        }
    }

    public function test_only_issuance_allocates_against_a_request(): void
    {
        $this->assertSame([StaffRole::InventoryControlOfficer], DepartmentPermissions::rolesHolding('requests.process'));
    }

    public function test_dispatch_has_no_clinical_or_laboratory_access(): void
    {
        foreach (DepartmentPermissions::forRole(StaffRole::DispatchCoordinator) as $ability) {
            $this->assertStringStartsNotWith('lab.', $ability);
            $this->assertStringStartsNotWith('donations.', $ability);
            $this->assertStringStartsNotWith('donors.', $ability);
        }
    }

    public function test_the_it_clerk_audits_and_requests_but_edits_nothing_directly(): void
    {
        $this->assertEqualsCanonicalizing(
            ['reference.view', 'reports.view_own', 'inventory.view', 'inventory.audit', 'corrections.request'],
            DepartmentPermissions::forRole(StaffRole::ItDataClerk)
        );

        foreach (['inventory.create', 'inventory.update', 'inventory.discard', 'inventory.release_quarantine'] as $direct) {
            $this->assertNotContains($direct, DepartmentPermissions::forRole(StaffRole::ItDataClerk));
        }
    }

    // --- Department heads ---------------------------------------------------------

    public function test_every_department_has_a_head_who_both_requests_and_approves(): void
    {
        foreach (Department::cases() as $department) {
            $head = $department->correctionApprover();

            $this->assertSame($department, $head->department(), "{$department->value}'s head must sit in {$department->value}.");
            $this->assertContains('corrections.request', DepartmentPermissions::forRole($head));
            $this->assertContains('corrections.approve', DepartmentPermissions::forRole($head));
        }
    }

    public function test_correction_approval_is_held_by_exactly_the_five_heads(): void
    {
        $heads = array_map(fn (Department $department): StaffRole => $department->correctionApprover(), Department::cases());

        $this->assertEqualsCanonicalizing(
            array_map(fn (StaffRole $role): string => $role->value, $heads),
            array_map(fn (StaffRole $role): string => $role->value, DepartmentPermissions::rolesHolding('corrections.approve'))
        );
        $this->assertCount(5, $heads);
    }

    public function test_the_billing_supervisor_is_the_billing_clerk_plus_approval(): void
    {
        $this->assertEqualsCanonicalizing(
            [...DepartmentPermissions::forRole(StaffRole::BillingClerk), 'corrections.approve'],
            DepartmentPermissions::forRole(StaffRole::BillingSupervisor)
        );
    }

    public function test_a_custom_role_never_inherits_a_named_posts_abilities(): void
    {
        foreach ([Department::Issuance, Department::Billing] as $department) {
            $abilities = User::factory()->bloodCenterCustomStaff(null, $department, 'Floor Officer')->create()->abilities();

            $this->assertNotContains('corrections.approve', $abilities);
            $this->assertNotContains('inventory.audit', $abilities);
        }

        // Still the department's own working set, including filing a request.
        $billing = User::factory()->bloodCenterCustomStaff(null, Department::Billing, 'Cashier')->create()->abilities();

        $this->assertContains('billing.record_payment', $billing);
        $this->assertContains('corrections.request', $billing);
    }

    public function test_collection_staff_cannot_discard_a_unit(): void
    {
        $facility = Facility::factory()->approved()->create();
        $collection = User::factory()->bloodCenterStaff($facility, StaffRole::Phlebotomist)->create();

        $this->actingAs($collection)
            ->postJson('/api/blood-center/inventory/RA1-1-01/discard', ['reason' => 'Damaged bag'])
            ->assertForbidden();
    }

    public function test_the_inventory_control_officer_reaches_every_inventory_route(): void
    {
        $facility = Facility::factory()->approved()->create();
        $officer = User::factory()->bloodCenterStaff($facility, StaffRole::InventoryControlOfficer)->create();

        $this->actingAs($officer)->getJson('/api/blood-center/inventory')->assertOk();
        $this->actingAs($officer)->getJson('/api/blood-center/inventory/summary')->assertOk();

        // 422 rather than 403: the gate passed and validation is what refused.
        $this->actingAs($officer)
            ->postJson('/api/blood-center/inventory', [])
            ->assertStatus(422);
    }

    public function test_the_permission_list_and_role_are_exposed_on_the_authenticated_user(): void
    {
        $staff = User::factory()->bloodCenterStaff(null, StaffRole::SerologyTechnologist)->create();

        $this->actingAs($staff)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.staff_role', 'serology_technologist')
            ->assertJsonPath('data.staff_role_label', 'Serology / Molecular Medical Technologist')
            ->assertJsonPath('data.department', 'testing')
            ->assertJsonPath('data.department_label', 'Testing')
            ->assertJsonPath('data.is_supervisor', false)
            ->assertJsonPath('data.permissions', DepartmentPermissions::forRole(StaffRole::SerologyTechnologist));
    }

    public function test_the_authenticated_user_payload_carries_the_facility(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        $this->actingAs($staff)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.facility.facility_name', $facility->name);
    }

    public function test_the_catalogue_lists_every_role_under_its_department(): void
    {
        $catalogue = DepartmentPermissions::catalogue();

        $this->assertSame(Department::values(), array_column($catalogue['departments'], 'department'));
        $this->assertSame(['read', 'write', 'update', 'delete'], array_column($catalogue['privileges'], 'key'));
        $this->assertSame(['RMT', 'RN'], $catalogue['titles']);

        $keys = [];

        foreach ($catalogue['departments'] as $department) {
            foreach ($department['roles'] as $role) {
                $keys[] = $role['key'];
                $this->assertSame(DepartmentPermissions::forRole(StaffRole::from($role['key'])), $role['abilities']);
                $this->assertNotSame('', $role['description']);
            }
        }

        $this->assertSame(StaffRole::values(), $keys);
    }
}
