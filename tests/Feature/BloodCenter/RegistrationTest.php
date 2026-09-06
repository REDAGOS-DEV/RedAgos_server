<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Facility self-registration is gone.
 *
 * This file used to prove a blood centre could sign itself up. It now proves
 * the opposite, because the ability to create a facility is the ability to
 * attach an account to real blood stock, and an approval queue behind a public
 * endpoint is a gate that only works if somebody is watching it.
 *
 * The complement lives in tests/Feature/Admin/FacilityManagementTest.php: the
 * Super Admin path that replaced this one.
 */
class RegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * The payload the removed endpoint used to accept.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'center_name' => 'Davao Regional Blood Center',
            'doh_license_number' => 'DOH-BC-2026-00412',
            'contact_first_name' => 'Maria',
            'contact_last_name' => 'Santos',
            'position' => 'Medical Technologist',
            'email' => 'maria.santos@drbc.ph',
            'phone' => '09171234567',
            'address' => 'Quirino Ave, Davao City',
            'description' => 'Regional blood collection and processing centre.',
            'password' => 'SecurePass1',
            'password_confirmation' => 'SecurePass1',
        ], $overrides);
    }

    public function test_the_public_blood_center_registration_endpoint_is_gone(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload())->assertNotFound();

        $this->assertDatabaseMissing('facilities', ['doh_license_number' => 'DOH-BC-2026-00412']);
        $this->assertDatabaseMissing('users', ['email' => 'maria.santos@drbc.ph']);
    }

    /**
     * Every path a client might guess at, given the endpoints that used to exist.
     *
     * @return array<string, array{string}>
     */
    public static function guessableRegistrationPaths(): array
    {
        return [
            'blood center' => ['/api/blood-center/register'],
            'blood center registration' => ['/api/blood-center/registration'],
            'blood bank' => ['/api/blood-bank/register'],
            'hospital' => ['/api/hospital/register'],
            'facilities' => ['/api/facilities/register'],
            'facility registrations' => ['/api/admin/facility-registrations'],
        ];
    }

    #[DataProvider('guessableRegistrationPaths')]
    public function test_no_public_route_creates_a_facility(string $uri): void
    {
        $this->postJson($uri, $this->payload())->assertNotFound();

        $this->assertSame(0, Facility::count());
    }

    /**
     * The one endpoint that does create a facility refuses an anonymous caller.
     */
    public function test_the_admin_creation_endpoint_refuses_an_unauthenticated_caller(): void
    {
        $this->postJson('/api/admin/facilities', [])->assertUnauthorized();

        $this->assertSame(0, Facility::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'donor' => ['donor'],
            'blood center staff' => ['blood_center'],
            'no role at all' => ['none'],
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_the_admin_creation_endpoint_refuses_every_non_admin(string $kind): void
    {
        $user = match ($kind) {
            'donor' => User::factory()->donor()->create(),
            'blood_center' => User::factory()->bloodCenterSupervisor()->create(),
            default => User::factory()->create(),
        };

        $this->actingAs($user)->postJson('/api/admin/facilities', [])->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/facilities')->assertForbidden();
    }

    /**
     * A facility account cannot promote itself into the onboarding role.
     */
    public function test_facility_staff_cannot_reach_facility_creation_through_their_own_portal(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor()->create();

        $this->assertTrue($supervisor->hasRole(RoleName::BloodCenter));

        $this->actingAs($supervisor)
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertForbidden();
    }
}
