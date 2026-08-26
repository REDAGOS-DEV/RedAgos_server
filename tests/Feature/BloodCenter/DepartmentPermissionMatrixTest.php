<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Models\Facility;
use App\Models\User;
use App\Support\DepartmentPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DepartmentPermissionMatrixTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_declared_ability_is_registered_as_a_gate(): void
    {
        foreach (DepartmentPermissions::all() as $ability) {
            $this->assertTrue(
                Gate::has($ability),
                "Ability [{$ability}] is declared in the matrix but no gate was defined for it."
            );
        }
    }

    public function test_each_department_holds_exactly_the_abilities_the_matrix_declares(): void
    {
        foreach (Department::cases() as $department) {
            $staff = User::factory()->bloodCenterStaff(null, $department)->create();

            $this->assertEqualsCanonicalizing(
                DepartmentPermissions::forDepartment($department),
                $staff->abilities(),
                "{$department->value} resolved a different ability set than the matrix declares."
            );
        }
    }

    public function test_no_operational_department_holds_a_management_ability(): void
    {
        foreach (Department::cases() as $department) {
            $abilities = DepartmentPermissions::forDepartment($department);

            foreach (['staff.manage', 'center.configure', 'reports.view_all'] as $management) {
                $this->assertNotContains(
                    $management,
                    $abilities,
                    "{$department->value} must not hold the management ability [{$management}]."
                );
            }
        }
    }

    public function test_a_supervisor_holds_every_ability_regardless_of_department(): void
    {
        $managementOnly = User::factory()->bloodCenterSupervisor()->create();
        $working = User::factory()->bloodCenterSupervisor(null, Department::Billing)->create();

        $this->assertEqualsCanonicalizing(DepartmentPermissions::all(), $managementOnly->abilities());

        // The department must not narrow the set: a billing supervisor still
        // holds inventory.create, which billing staff never do.
        $this->assertEqualsCanonicalizing(DepartmentPermissions::all(), $working->abilities());
        $this->assertContains('inventory.create', $working->abilities());
    }

    public function test_staff_without_a_department_hold_nothing(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->department = null;
        $staff->save();

        $this->assertSame([], $staff->fresh()->abilities());
    }

    public function test_a_department_less_account_cannot_reach_an_operational_endpoint(): void
    {
        // reference-data is shared by all four departments, which is exactly why
        // it needs an ability of its own — role plus facility status alone would
        // wave an unassigned account straight through.
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->department = null;
        $staff->save();

        $this->actingAs($staff->fresh())
            ->getJson('/api/blood-center/reference-data')
            ->assertForbidden();
    }

    public function test_billing_staff_cannot_write_inventory_but_may_read_billing(): void
    {
        $facility = Facility::factory()->approved()->create();
        $billing = User::factory()->bloodCenterStaff($facility, Department::Billing)->create();

        $this->actingAs($billing)
            ->postJson('/api/blood-center/inventory', [])
            ->assertForbidden();

        $this->assertContains('billing.record_payment', $billing->abilities());
        $this->assertNotContains('inventory.create', $billing->abilities());
    }

    public function test_laboratory_staff_may_read_inventory_but_not_write_it(): void
    {
        $facility = Facility::factory()->approved()->create();
        $laboratory = User::factory()->bloodCenterStaff($facility, Department::Laboratory)->create();

        $this->actingAs($laboratory)
            ->getJson('/api/blood-center/inventory')
            ->assertOk();

        $this->actingAs($laboratory)
            ->postJson('/api/blood-center/inventory', [])
            ->assertForbidden();
    }

    public function test_collection_staff_cannot_discard_a_unit(): void
    {
        $facility = Facility::factory()->approved()->create();
        $collection = User::factory()->bloodCenterStaff($facility, Department::Collection)->create();

        $this->actingAs($collection)
            ->postJson('/api/blood-center/inventory/RA1-1-01/discard', ['reason' => 'Damaged bag'])
            ->assertForbidden();
    }

    public function test_inventory_staff_reach_every_inventory_route(): void
    {
        $facility = Facility::factory()->approved()->create();
        $inventory = User::factory()->bloodCenterStaff($facility, Department::Inventory)->create();

        $this->actingAs($inventory)->getJson('/api/blood-center/inventory')->assertOk();
        $this->actingAs($inventory)->getJson('/api/blood-center/inventory/summary')->assertOk();

        // 422 rather than 403: the gate passed and validation is what refused.
        $this->actingAs($inventory)
            ->postJson('/api/blood-center/inventory', [])
            ->assertStatus(422);
    }

    public function test_the_permission_list_is_exposed_on_the_authenticated_user(): void
    {
        $staff = User::factory()->bloodCenterStaff(null, Department::Laboratory)->create();

        $this->actingAs($staff)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.department', 'laboratory')
            ->assertJsonPath('data.department_label', 'Laboratory / Processing')
            ->assertJsonPath('data.is_supervisor', false)
            ->assertJsonPath('data.permissions', DepartmentPermissions::forDepartment(Department::Laboratory));
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
}
