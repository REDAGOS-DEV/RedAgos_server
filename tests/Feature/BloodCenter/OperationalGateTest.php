<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OperationalGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * The first route behind facility.operational. Every Module 2 endpoint
     * joins the same group, so this stands in for all of them.
     */
    private const OPERATIONAL_ROUTE = '/api/blood-center/reference-data';

    public function test_approved_and_verified_staff_are_allowed_through(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff()->create())
            ->getJson(self::OPERATIONAL_ROUTE)
            ->assertOk();
    }

    public function test_an_unverified_account_is_refused(): void
    {
        // Holds the role and belongs to an approved facility. Only the email is
        // unconfirmed — and PendingVerification is allowed to authenticate, so
        // without this gate the token would work everywhere.
        $staff = User::factory()->unverified()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->getJson(self::OPERATIONAL_ROUTE)
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_staff_of_a_suspended_facility_are_refused(): void
    {
        $facility = Facility::factory()->suspended()->create();
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        // The role is still attached — suspension does not strip it — so the
        // role middleware alone would wave this request straight through.
        $this->assertTrue($staff->hasRole(RoleName::BloodCenter));

        $this->actingAs($staff)
            ->getJson(self::OPERATIONAL_ROUTE)
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_suspended');
    }

    public function test_staff_of_a_pending_facility_are_refused(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        $this->actingAs($staff)
            ->getJson(self::OPERATIONAL_ROUTE)
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_approved');
    }

    public function test_a_role_holder_with_no_facility_is_refused(): void
    {
        $user = User::factory()->withRole(RoleName::BloodCenter)->create();

        $this->actingAs($user)
            ->getJson(self::OPERATIONAL_ROUTE)
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_missing');
    }

    public function test_a_donor_is_refused(): void
    {
        $this->actingAs(User::factory()->donor()->create())
            ->getJson(self::OPERATIONAL_ROUTE)
            ->assertForbidden();
    }

    public function test_the_operational_route_rejects_unauthenticated_callers(): void
    {
        $this->getJson(self::OPERATIONAL_ROUTE)->assertUnauthorized();
    }

    public function test_profile_stays_reachable_while_the_facility_is_suspended(): void
    {
        $facility = Facility::factory()->suspended()->create();
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        // Group C sits outside the operational gate on purpose, so a blocked
        // user can still read why they are blocked.
        $this->actingAs($staff)
            ->getJson('/api/blood-center/profile')
            ->assertOk()
            ->assertJsonPath('facility.status', 'suspended');
    }

    public function test_profile_stays_reachable_while_the_email_is_unverified(): void
    {
        $staff = User::factory()->unverified()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/profile')
            ->assertOk()
            ->assertJsonPath('account.email_verified', false);
    }

    public function test_password_change_stays_reachable_while_suspended(): void
    {
        $facility = Facility::factory()->suspended()->create();
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        $this->actingAs($staff)
            ->postJson('/api/blood-center/password', [
                'current_password' => 'password',
                'password' => 'BrandNewPass1',
                'password_confirmation' => 'BrandNewPass1',
            ])
            ->assertOk();
    }

    public function test_a_pending_applicant_can_log_in_and_the_response_carries_the_facility_status(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        User::factory()->bloodCenterApplicant($facility)->create([
            'email' => 'applicant@example.ph',
        ]);

        // The client needs facility.status in the login response itself to route
        // to the registration-status screen rather than the dashboard.
        $this->postJson('/api/login', [
            'email' => 'applicant@example.ph',
            'password' => 'password',
            'role' => 'blood-center',
        ])
            ->assertOk()
            ->assertJsonPath('user.facility.status', 'pending_approval')
            ->assertJsonPath('user.roles', []);
    }

    public function test_a_donor_signing_in_through_the_blood_center_portal_is_still_refused(): void
    {
        User::factory()->donor()->create(['email' => 'donor@example.ph']);

        // The leniency above is narrow: it applies only to organisation roles
        // with an unapproved facility, never to a donor at the wrong portal.
        $this->postJson('/api/login', [
            'email' => 'donor@example.ph',
            'password' => 'password',
            'role' => 'blood-center',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'role_mismatch');
    }

    public function test_an_applicants_token_is_refused_by_every_guarded_route(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        // Authentication succeeds for an applicant; authorization does not.
        $this->actingAs($applicant)->getJson(self::OPERATIONAL_ROUTE)->assertForbidden();
        $this->actingAs($applicant)->getJson('/api/blood-center/profile')->assertForbidden();
        $this->actingAs($applicant)->getJson('/api/admin/facility-registrations')->assertForbidden();

        // Their own applicant route stays open.
        $this->actingAs($applicant)->getJson('/api/blood-center/registration-status')->assertOk();
    }
}
