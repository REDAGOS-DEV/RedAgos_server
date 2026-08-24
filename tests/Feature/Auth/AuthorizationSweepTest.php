<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
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

    public function test_public_auth_routes_do_not_require_a_token(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422);
        $this->postJson('/api/forgot-password', [])->assertStatus(422);
        $this->postJson('/api/reset-password', [])->assertStatus(422);
        $this->postJson('/api/donors/register', [])->assertStatus(422);
        $this->postJson('/api/blood-center/register', [])->assertStatus(422);
    }
}
