<?php

namespace Tests\Feature\Auth;

use App\Enums\Department;
use App\Enums\RoleName;
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
            'blood center registration status' => ['get', '/api/blood-center/registration-status'],
            'blood center resubmit' => ['post', '/api/blood-center/registration/resubmit'],
            'blood center profile' => ['get', '/api/blood-center/profile'],
            'blood center password' => ['post', '/api/blood-center/password'],
            'blood center reference data' => ['get', '/api/blood-center/reference-data'],
            'admin facility registrations' => ['get', '/api/admin/facility-registrations'],
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
        ];
    }

    /**
     * Blood-centre staff holding the role but no department must be refused everywhere.
     *
     * This is the regression that matters most: before departments existed the
     * role alone opened all of these, so an account that slips through without
     * an assignment must fail closed rather than inherit the old behaviour.
     */
    #[DataProvider('departmentGatedRoutes')]
    public function test_department_gated_routes_reject_staff_with_no_department(string $method, string $uri): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->department = null;
        $staff->save();

        $this->actingAs($staff->fresh())->json($method, $uri)->assertForbidden();
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

    public function test_a_donor_cannot_reach_a_blood_center_route_even_with_a_department_column_set(): void
    {
        // department is a plain column, so it can be set on an account that
        // holds no blood_center role. role: must still refuse first.
        $donor = User::factory()->donor()->create();
        $donor->department = Department::Inventory;
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
        $this->postJson('/api/blood-center/register', [])->assertStatus(422);
    }
}
