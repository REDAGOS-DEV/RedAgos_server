<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\ManagementLevel;
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

    public function test_a_management_level_is_never_capped(): void
    {
        // Privileges cap a role. The Admin's staff.manage is an update-shaped
        // power, but no Read tick takes it away.
        $admin = User::factory()->bloodCenterAdmin()->withPrivileges(['read'])->create();

        $this->assertEqualsCanonicalizing(DepartmentPermissions::forLevel(ManagementLevel::Admin), $admin->abilities());
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

    // --- Management levels (project owner, 2026-10-15) --------------------------

    public function test_the_supervisor_only_watches(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor()->create();

        $this->assertEqualsCanonicalizing([
            'reference.view',
            'reports.view_own',
            'reports.view_all',
            'inventory.view',
            'inventory.stock_report',
            'requests.view',
            'billing.view',
        ], $supervisor->abilities());

        // Every one of them a read.
        foreach ($supervisor->abilities() as $ability) {
            if ($ability !== 'reports.view_all') {
                $this->assertSame('read', DepartmentPermissions::kindOf($ability), "[{$ability}] is not a read.");
            }
        }
    }

    public function test_the_admin_holds_the_overview_staff_and_settings_and_nothing_else(): void
    {
        $admin = User::factory()->bloodCenterAdmin()->create();
        $abilities = $admin->abilities();

        // Project owner, 2026-10-15: no Issuance or Billing pages, and no
        // corrections, for the Admin.
        $this->assertEqualsCanonicalizing([
            'reference.view',
            'reports.view_own',
            'reports.view_all',
            'staff.manage',
            'center.configure',
        ], $abilities);

        foreach ([
            'inventory.view', 'inventory.stock_report', 'requests.view', 'billing.view',
            'corrections.request', 'corrections.approve',
            'donations.register', 'donations.screen', 'donations.collect', 'donations.record_paper', 'lab.record_serology',
            'lab.record_components', 'lab.update_status', 'inventory.create', 'inventory.update',
            'inventory.discard', 'inventory.thresholds', 'inventory.release_quarantine',
            'requests.approve', 'requests.release', 'billing.record_payment',
            'components.configure', 'billing.prices', 'donors.view_identity', 'donors.view_clinical',
        ] as $work) {
            $this->assertNotContains($work, $abilities, "The Admin must not hold [{$work}].");
        }
    }

    public function test_a_level_adds_to_a_role_and_the_role_stays_capped(): void
    {
        $working = User::factory()->bloodCenterSupervisor(null, StaffRole::BillingClerk)
            ->withPrivileges(['read'])
            ->create();

        $abilities = $working->abilities();

        // The level's monitoring, uncapped.
        $this->assertContains('inventory.stock_report', $abilities);
        $this->assertContains('reports.view_all', $abilities);

        // The role's reads survive the cap; its writes do not.
        $this->assertContains('billing.view', $abilities);
        $this->assertNotContains('billing.record_payment', $abilities);
        $this->assertNotContains('inventory.create', $abilities);
    }

    public function test_the_supervisor_is_refused_every_write_it_could_otherwise_reach(): void
    {
        $facility = Facility::factory()->approved()->create();
        $supervisor = User::factory()->bloodCenterSupervisor($facility)->create();

        $this->actingAs($supervisor)->getJson('/api/blood-center/inventory')->assertOk();
        $this->actingAs($supervisor)->getJson('/api/blood-center/inventory/stock-report')->assertOk();
        $this->actingAs($supervisor)->getJson('/api/blood-center/blood-requests')->assertOk();
        $this->actingAs($supervisor)->getJson('/api/blood-center/billings')->assertOk();

        $this->actingAs($supervisor)->postJson('/api/blood-center/inventory', [])->assertForbidden();
        $this->actingAs($supervisor)->postJson('/api/blood-center/inventory/RA1-1-01/discard', ['reason' => 'x'])->assertForbidden();
        $this->actingAs($supervisor)->postJson('/api/blood-center/blood-requests/1/reject', [])->assertForbidden();
        $this->actingAs($supervisor)->postJson('/api/blood-center/billings/1/payments', [])->assertForbidden();
        $this->actingAs($supervisor)->getJson('/api/blood-center/corrections')->assertForbidden();
        $this->actingAs($supervisor)->getJson('/api/blood-center/laboratory/queue')->assertForbidden();
        $this->actingAs($supervisor)->getJson('/api/blood-center/donors')->assertForbidden();
    }

    public function test_the_admin_reaches_administration_but_no_department_write(): void
    {
        $facility = Facility::factory()->approved()->create();
        $admin = User::factory()->bloodCenterAdmin($facility)->create();

        $this->actingAs($admin)->getJson('/api/blood-center/staff')->assertOk();

        foreach ([
            '/api/blood-center/corrections?scope=review',
            '/api/blood-center/inventory',
            '/api/blood-center/inventory/activity',
            '/api/blood-center/inventory/thresholds',
            '/api/blood-center/inventory/stock-report',
            '/api/blood-center/blood-requests',
            '/api/blood-center/billings',
        ] as $page) {
            $this->actingAs($admin)->getJson($page)->assertForbidden();
        }

        $this->actingAs($admin)->postJson('/api/blood-center/inventory', [])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/blood-center/blood-components')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/blood-center/component-prices')->assertForbidden();
        $this->actingAs($admin)->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => []])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/blood-center/pos/queue')->assertForbidden();
    }

    public function test_the_daily_stock_report_is_issuances_and_the_supervisors_only(): void
    {
        $this->assertSame([StaffRole::InventoryControlOfficer], DepartmentPermissions::rolesHolding('inventory.stock_report'));

        $facility = Facility::factory()->approved()->create();
        $lab = User::factory()->bloodCenterStaff($facility, StaffRole::ComponentTechnologist)->create();

        // The laboratory sees the inventory, but not the sheet Issuance signs.
        $this->actingAs($lab)->getJson('/api/blood-center/inventory/stock-report')->assertForbidden();
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

    /**
     * Project owner, 2026-10-19: Processing hands units over and never works
     * on stock, so neither its roles nor a custom Processing role read
     * inventory, as Testing does not.
     */
    public function test_processing_cannot_read_or_write_inventory(): void
    {
        $this->assertNotContains('inventory.view', DepartmentPermissions::forDepartment(Department::Processing));

        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility, StaffRole::ComponentTechnologist)->create();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/inventory')
            ->assertForbidden();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/inventory/thresholds')
            ->assertForbidden();

        $this->actingAs($staff)
            ->postJson('/api/blood-center/inventory', [])
            ->assertForbidden();
    }

    /**
     * Project owner, 2026-10-18: Testing works on drawn donations, never on
     * stock, so neither its roles nor a custom Testing role read inventory.
     */
    public function test_testing_cannot_read_inventory(): void
    {
        $this->assertNotContains('inventory.view', DepartmentPermissions::forDepartment(Department::Testing));

        $facility = Facility::factory()->approved()->create();
        $supervisor = User::factory()->bloodCenterStaff($facility, StaffRole::LabSupervisor)->create();

        $this->actingAs($supervisor)
            ->getJson('/api/blood-center/inventory/thresholds')
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
     * A technologist's reactive result waits for a second person (project
     * owner, 2026-10-18). That person is the supervisor's named post: no other
     * role and no custom Testing role holds it.
     */
    public function test_only_the_lab_supervisor_verifies_a_reactive_result(): void
    {
        $this->assertSame([StaffRole::LabSupervisor], DepartmentPermissions::rolesHolding('lab.verify_serology'));
        $this->assertNotContains('lab.verify_serology', DepartmentPermissions::forCustomRole(Department::Testing));
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

    /**
     * A drive's paper records are typed by whoever brings the sheets home
     * (project owner, 2026-10-17): every Collection role, the head included,
     * and nobody outside the department.
     */
    public function test_every_collection_role_and_no_other_types_paper_records(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                StaffRole::ScreeningPhysician,
                StaffRole::Phlebotomist,
                StaffRole::ApheresisSpecialist,
                StaffRole::MedicalReceptionist,
            ],
            DepartmentPermissions::rolesHolding('donations.record_paper')
        );
    }

    /**
     * The Donation desk prints a booked donor's completed questionnaire and
     * hands a walk-in the counter's device to answer it (project owner,
     * 2026-10-16). Printing is not reviewing: the receptionist still lacks the
     * physician's read, which is the one carrying the review markers.
     */
    public function test_the_desk_and_the_physician_print_and_take_the_questionnaire_and_nobody_else(): void
    {
        foreach (['donors.print_questionnaire', 'donors.record_questionnaire'] as $ability) {
            $this->assertEqualsCanonicalizing(
                [StaffRole::ScreeningPhysician, StaffRole::MedicalReceptionist],
                DepartmentPermissions::rolesHolding($ability),
                "Only the desk and the physician may hold [{$ability}]."
            );
        }

        $this->assertNotContains('donors.view_questionnaire', DepartmentPermissions::forRole(StaffRole::MedicalReceptionist));
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

    public function test_the_billing_supervisor_is_the_billing_clerk_plus_approval_and_prices(): void
    {
        $this->assertEqualsCanonicalizing(
            [...DepartmentPermissions::forRole(StaffRole::BillingClerk), 'corrections.approve', 'billing.prices'],
            DepartmentPermissions::forRole(StaffRole::BillingSupervisor)
        );
    }

    public function test_each_department_head_alone_keeps_its_settings(): void
    {
        $this->assertSame([StaffRole::ComponentTechnologist], DepartmentPermissions::rolesHolding('components.configure'));
        $this->assertSame([StaffRole::BillingSupervisor], DepartmentPermissions::rolesHolding('billing.prices'));
        $this->assertSame([StaffRole::InventoryControlOfficer], DepartmentPermissions::rolesHolding('inventory.thresholds'));
    }

    public function test_a_custom_role_never_inherits_a_named_posts_abilities(): void
    {
        foreach ([Department::Issuance, Department::Billing, Department::Processing] as $department) {
            $abilities = User::factory()->bloodCenterCustomStaff(null, $department, 'Floor Officer')->create()->abilities();

            $this->assertNotContains('corrections.approve', $abilities);
            $this->assertNotContains('inventory.audit', $abilities);
            $this->assertNotContains('components.configure', $abilities);
            $this->assertNotContains('billing.prices', $abilities);
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
            ->assertJsonPath('data.management_level', null)
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
