<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Models\User;
use App\Support\DepartmentPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationSweepTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Every route that must reject an unauthenticated caller.
     *
     * @return array<string, array{string, string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'logout' => ['post', '/api/logout'],
            'logout all' => ['post', '/api/logout-all'],
            'resend verification' => ['post', '/api/email/verification-notification'],
            'current user' => ['get', '/api/user'],
            'donor dashboard' => ['get', '/api/donors/dashboard'],
            'donor profile' => ['get', '/api/donors/profile'],
            'update donor profile' => ['patch', '/api/donors/profile'],
            'update donor password' => ['post', '/api/donors/password'],
            'notification preferences' => ['patch', '/api/donors/notification-preferences'],
            'admin user list' => ['get', '/api/users'],
            'blood center profile' => ['get', '/api/blood-center/profile'],
            'blood center password' => ['post', '/api/blood-center/password'],
            'blood center reference data' => ['get', '/api/blood-center/reference-data'],
            'admin facility list' => ['get', '/api/admin/facilities'],
            'admin facility create' => ['post', '/api/admin/facilities'],
            'hospital availability search' => ['get', '/api/hospital/availability'],
            'hospital eligible facilities' => ['get', '/api/hospital/facilities'],
            'hospital request list' => ['get', '/api/hospital/blood-requests'],
            'hospital request create' => ['post', '/api/hospital/blood-requests'],
            'hospital request show' => ['get', '/api/hospital/blood-requests/1'],
            'hospital request cancel' => ['post', '/api/hospital/blood-requests/1/cancel'],
            'hospital confirm receipt' => ['post', '/api/hospital/blood-requests/1/confirm-receipt'],
            'hospital notifications' => ['get', '/api/hospital/notifications'],
            'centre incoming queue' => ['get', '/api/blood-center/blood-requests'],
            'centre queue summary' => ['get', '/api/blood-center/blood-requests/summary'],
            'centre request review' => ['get', '/api/blood-center/blood-requests/1'],
            'centre allocate' => ['post', '/api/blood-center/blood-requests/1/allocate'],
            'centre reject' => ['post', '/api/blood-center/blood-requests/1/reject'],
            'centre release holds' => ['post', '/api/blood-center/blood-requests/1/release-holds'],
            'centre release' => ['post', '/api/blood-center/blood-requests/1/release'],
            'centre request history' => ['get', '/api/blood-center/blood-requests/1/history'],
            'centre close line' => ['post', '/api/blood-center/blood-requests/1/items/1/close'],
            'centre walk-in reference' => ['get', '/api/blood-center/blood-requests/walk-in/reference'],
            'centre walk-in duplicates' => ['post', '/api/blood-center/blood-requests/walk-in/duplicates'],
            'centre walk-in record' => ['post', '/api/blood-center/blood-requests/walk-in'],
            'hospital request history' => ['get', '/api/hospital/blood-requests/1/history'],
            'hospital request follow-up' => ['post', '/api/hospital/blood-requests/1/follow-up'],
            'hospital close line' => ['post', '/api/hospital/blood-requests/1/items/1/close'],
            'hospital patient matches' => ['post', '/api/hospital/blood-requests/patient-matches'],
            'centre billing show' => ['get', '/api/blood-center/billings/1'],
            'centre record payment' => ['post', '/api/blood-center/billings/1/payments'],
            'centre notifications' => ['get', '/api/blood-center/notifications'],
            'testing immunohematology' => ['post', '/api/blood-center/laboratory/donations/1/immunohematology'],
            'testing serology' => ['post', '/api/blood-center/laboratory/donations/1/serology'],
            'testing referral list' => ['get', '/api/blood-center/laboratory/referrals'],
            'testing referral update' => ['patch', '/api/blood-center/laboratory/referrals/1'],
            'daily stock report' => ['get', '/api/blood-center/inventory/stock-report'],
            'daily stock report pdf' => ['get', '/api/blood-center/inventory/stock-report/pdf'],
            'facility logo upload' => ['post', '/api/blood-center/facility/logo'],
            'final bag labels' => ['get', '/api/blood-center/inventory/donations/1/labels'],
            'facility logo removal' => ['delete', '/api/blood-center/facility/logo'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_protected_routes_reject_unauthenticated_callers(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public function test_the_admin_user_endpoints_reject_a_donor(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)->getJson('/api/users')->assertForbidden();
    }

    public function test_the_admin_user_endpoints_accept_an_admin(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
    }

    public function test_a_user_with_no_roles_is_refused_by_the_role_middleware(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/users')->assertForbidden();
    }

    /**
     * Every blood-centre route behind a department ability, with the ability it demands.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function departmentGatedRoutes(): array
    {
        return [
            'reference data' => ['get', '/api/blood-center/reference-data', 'reference.view'],
            'inventory list' => ['get', '/api/blood-center/inventory', 'inventory.view'],
            'inventory summary' => ['get', '/api/blood-center/inventory/summary', 'inventory.view'],
            'record units' => ['post', '/api/blood-center/inventory', 'inventory.create'],
            'update unit' => ['patch', '/api/blood-center/inventory/RA1-1-01', 'inventory.update'],
            'discard unit' => ['post', '/api/blood-center/inventory/RA1-1-01/discard', 'inventory.discard'],
            'incoming queue' => ['get', '/api/blood-center/blood-requests', 'requests.view'],
            'queue summary' => ['get', '/api/blood-center/blood-requests/summary', 'requests.view'],
            'request review' => ['get', '/api/blood-center/blood-requests/1', 'requests.view'],
            'allocate units' => ['post', '/api/blood-center/blood-requests/1/allocate', 'requests.process'],
            'reject request' => ['post', '/api/blood-center/blood-requests/1/reject', 'requests.approve'],
            'release holds' => ['post', '/api/blood-center/blood-requests/1/release-holds', 'requests.approve'],
            'release units' => ['post', '/api/blood-center/blood-requests/1/release', 'requests.release'],
            'request history' => ['get', '/api/blood-center/blood-requests/1/history', 'requests.view'],
            'close request line' => ['post', '/api/blood-center/blood-requests/1/items/1/close', 'requests.approve'],
            'walk-in reference' => ['get', '/api/blood-center/blood-requests/walk-in/reference', 'requests.record'],
            'walk-in duplicates' => ['post', '/api/blood-center/blood-requests/walk-in/duplicates', 'requests.record'],
            'record walk-in' => ['post', '/api/blood-center/blood-requests/walk-in', 'requests.record'],
            'billing show' => ['get', '/api/blood-center/billings/1', 'billing.view'],
            'record payment' => ['post', '/api/blood-center/billings/1/payments', 'billing.record_payment'],
            'donor list' => ['get', '/api/blood-center/donors', 'donors.view_contact'],
            'donor lookup' => ['get', '/api/blood-center/donors/lookup', 'appointments.verify'],
            'donor history' => ['get', '/api/blood-center/donors/00000000-0000-4000-8000-000000000000/history', 'donors.view_clinical'],
            'open donation' => ['post', '/api/blood-center/donations', 'donations.register'],
            'record screening' => ['post', '/api/blood-center/donations/1/screening', 'donations.screen'],
            'record collection' => ['post', '/api/blood-center/donations/1/collection', 'donations.collect'],
            'close donation' => ['patch', '/api/blood-center/donations/1/status', 'donations.close'],
            'record immunohematology' => ['post', '/api/blood-center/laboratory/donations/1/immunohematology', 'lab.record_immunohematology'],
            'record serology' => ['post', '/api/blood-center/laboratory/donations/1/serology', 'lab.record_serology'],
            'referral list' => ['get', '/api/blood-center/laboratory/referrals', 'lab.referrals'],
            'staff roles catalogue' => ['get', '/api/blood-center/staff/roles', 'staff.manage'],
            'release from quarantine' => ['post', '/api/blood-center/inventory/quarantine/1/release', 'inventory.release_quarantine'],
            'correction list' => ['get', '/api/blood-center/corrections', 'corrections.request'],
            'request correction' => ['post', '/api/blood-center/donations/1/corrections', 'corrections.request'],
            'approve correction' => ['post', '/api/blood-center/corrections/1/approve', 'corrections.approve'],
            'reject correction' => ['post', '/api/blood-center/corrections/1/reject', 'corrections.approve'],
        ];
    }

    /**
     * Blood-centre staff holding the access role but no staff role must be refused everywhere.
     *
     * This is the regression that matters most: before departments existed the
     * access role alone opened all of these, so an account that slips through
     * without an assignment must fail closed rather than inherit the old
     * behaviour.
     */
    #[DataProvider('departmentGatedRoutes')]
    public function test_department_gated_routes_reject_staff_with_no_role(string $method, string $uri): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->staff_role = null;
        $staff->save();

        $this->actingAs($staff->fresh())->json($method, $uri)->assertForbidden();
    }

    /**
     * Each role reaches exactly the gated routes whose ability it holds.
     *
     * The route table above states the ability each route demands, and the
     * matrix states what each role holds; this is the check that the two
     * agree on every pairing, not just that a supervisor gets through.
     */
    public function test_each_role_is_admitted_exactly_where_the_matrix_says(): void
    {
        foreach (StaffRole::cases() as $role) {
            $staff = User::factory()->bloodCenterStaff(null, $role)->create();
            $holds = DepartmentPermissions::forRole($role);

            foreach (self::departmentGatedRoutes() as $name => [$method, $uri, $ability]) {
                $status = $this->actingAs($staff)->json($method, $uri)->getStatusCode();

                if (in_array($ability, $holds, true)) {
                    $this->assertNotSame(403, $status, "{$role->value} holds [{$ability}] but was refused {$name}.");
                } else {
                    $this->assertSame(403, $status, "{$role->value} lacks [{$ability}] but reached {$name}.");
                }
            }
        }
    }

    #[DataProvider('departmentGatedRoutes')]
    public function test_every_department_gated_route_demands_a_declared_ability(string $method, string $uri, string $ability): void
    {
        $this->assertContains(
            $ability,
            DepartmentPermissions::all(),
            "Route [{$uri}] is gated on [{$ability}], which the matrix does not declare."
        );
    }

    public function test_a_supervisor_reaches_every_department_gated_route(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor()->create();

        // Only the gate is under test, so anything other than 403 is a pass —
        // a 404 or 422 past the gate is the middleware having let them through.
        foreach (self::departmentGatedRoutes() as [$method, $uri]) {
            $response = $this->actingAs($supervisor)->json($method, $uri);

            $this->assertNotSame(403, $response->getStatusCode(), "A supervisor was refused [{$uri}].");
        }
    }

    public function test_a_donor_cannot_reach_a_blood_center_route_even_with_a_staff_role_set(): void
    {
        // staff_role is a plain column, so it can be set on an account that
        // holds no blood_center access role. role: must still refuse first.
        $donor = User::factory()->donor()->create();
        $donor->staff_role = StaffRole::InventoryControlOfficer;
        $donor->save();

        $this->actingAs($donor->fresh())
            ->getJson('/api/blood-center/inventory')
            ->assertForbidden();
    }

    public function test_public_auth_routes_do_not_require_a_token(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422);
        $this->postJson('/api/forgot-password', [])->assertStatus(422);
        $this->postJson('/api/reset-password', [])->assertStatus(422);
        $this->postJson('/api/donors/register', [])->assertStatus(422);
    }

    /**
     * Donor registration is the only self-service account creation left.
     *
     * Both facility types are onboarded by a Super Admin, so a public caller
     * must find no route at all rather than a validation error that suggests
     * one exists behind the right payload.
     *
     * @return array<string, array{string, string}>
     */
    public static function removedPublicFacilityRoutes(): array
    {
        return [
            'blood center register' => ['post', '/api/blood-center/register'],
            'blood center registration status' => ['get', '/api/blood-center/registration-status'],
            'blood center resubmit' => ['post', '/api/blood-center/registration/resubmit'],
            'blood bank register' => ['post', '/api/blood-bank/register'],
            'facility register' => ['post', '/api/facilities/register'],
        ];
    }

    #[DataProvider('removedPublicFacilityRoutes')]
    public function test_public_facility_registration_routes_do_not_exist(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertNotFound();
    }
}
