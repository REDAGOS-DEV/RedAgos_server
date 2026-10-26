<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_donor_can_log_in_and_receive_a_token(): void
    {
        $user = User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'token', 'token_type', 'must_verify_email'])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'donor@example.com')
            ->assertJsonPath('user.roles', [RoleName::Donor->value])
            ->assertJsonPath('must_verify_email', false);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_the_email_address_is_matched_case_insensitively(): void
    {
        User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => '  DONOR@Example.com ',
            'password' => 'Password123',
        ])->assertOk();
    }

    public function test_a_user_can_sign_in_with_their_username(): void
    {
        User::factory()->withRole(RoleName::BloodCenter)->create([
            'email' => 'maria@example.com',
            'username' => 'maria-a8k2qz',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'login' => ' maria-a8k2qz ',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'maria@example.com');
    }

    public function test_the_login_field_also_takes_an_email_address(): void
    {
        User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'login' => '  DONOR@Example.com ',
            'password' => 'Password123',
        ])->assertOk();
    }

    public function test_a_username_is_matched_exactly(): void
    {
        User::factory()->donor()->create([
            'username' => 'p.guzman',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'login' => 'P.Guzman',
            'password' => 'Password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_an_unknown_username_answers_like_a_wrong_password(): void
    {
        User::factory()->donor()->create([
            'username' => 'juan-x8k2qa',
            'password' => 'Password123',
        ]);

        $unknown = $this->postJson('/api/login', ['login' => 'nobody-here', 'password' => 'Password123']);
        $wrong = $this->postJson('/api/login', ['login' => 'juan-x8k2qa', 'password' => 'WrongPassword1']);

        $unknown->assertStatus(422)->assertJsonValidationErrors('login');
        $wrong->assertStatus(422)->assertJsonValidationErrors('login');
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_an_email_address_wins_over_a_username_that_looks_like_one(): void
    {
        User::factory()->donor()->create([
            'email' => 'ana@example.com',
            'password' => 'AnasPassword1',
        ]);
        User::factory()->donor()->create([
            'email' => 'other@example.com',
            'username' => 'ana@example.com',
            'password' => 'OthersPassword1',
        ]);

        $this->postJson('/api/login', ['login' => 'ana@example.com', 'password' => 'AnasPassword1'])
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@example.com');

        $this->postJson('/api/login', ['login' => 'ana@example.com', 'password' => 'OthersPassword1'])
            ->assertStatus(422);
    }

    public function test_an_unverified_email_address_is_refused_with_a_distinct_code(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'unverified@example.com',
            'password' => 'Password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');

        // No token is issued, so nothing about the account is reachable.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_same_account_can_log_in_once_the_address_is_verified(): void
    {
        $user = User::factory()->unverified()->donor()->create([
            'email' => 'unverified@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'unverified@example.com',
            'password' => 'Password123',
            'role' => 'donor',
        ])->assertStatus(403);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->postJson('/api/login', [
            'email' => 'unverified@example.com',
            'password' => 'Password123',
            'role' => 'donor',
        ])->assertOk()->assertJsonPath('must_verify_email', false);
    }

    public function test_a_wrong_password_is_rejected_with_a_generic_message(): void
    {
        User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'WrongPassword1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
    }

    public function test_an_unknown_email_returns_the_same_message_as_a_wrong_password(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
    }

    public function test_a_soft_deleted_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'gone@example.com',
            'password' => 'Password123',
        ]);
        $user->delete();

        $this->postJson('/api/login', [
            'email' => 'gone@example.com',
            'password' => 'Password123',
        ])->assertStatus(422);
    }

    public function test_a_suspended_account_is_refused_with_a_distinct_code(): void
    {
        User::factory()->suspended()->create([
            'email' => 'suspended@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'suspended@example.com',
            'password' => 'Password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_suspended');
    }

    public function test_a_deactivated_account_is_refused_with_a_distinct_code(): void
    {
        User::factory()->deactivated()->create([
            'email' => 'deactivated@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'deactivated@example.com',
            'password' => 'Password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_deactivated');
    }

    public function test_a_pending_verification_account_cannot_log_in(): void
    {
        User::factory()->unverified()->donor()->create([
            'email' => 'pending@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'pending@example.com',
            'password' => 'Password123',
            'role' => 'donor',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_a_suspended_account_is_refused_before_the_verification_check(): void
    {
        // Both guards would refuse this account. The suspension is the one an
        // administrator imposed, so it is the one worth reporting — telling
        // them to check their inbox would send them chasing a dead end.
        User::factory()->unverified()->suspended()->create([
            'email' => 'suspended@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'suspended@example.com',
            'password' => 'Password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_suspended');
    }

    public function test_signing_in_through_a_portal_the_account_does_not_belong_to_is_refused(): void
    {
        User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'Password123',
            'role' => 'admin',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'role_mismatch');
    }

    public function test_a_kebab_case_role_alias_is_normalised_before_comparison(): void
    {
        User::factory()->withRole(RoleName::BloodCenter)->create([
            'email' => 'center@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'center@example.com',
            'password' => 'Password123',
            'role' => 'blood-center',
        ])->assertOk();
    }

    public function test_the_hospital_alias_maps_to_the_blood_bank_role(): void
    {
        User::factory()->withRole(RoleName::BloodBank)->create([
            'email' => 'hospital@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'hospital@example.com',
            'password' => 'Password123',
            'role' => 'hospital',
        ])->assertOk();
    }

    public function test_an_unknown_role_is_rejected_by_validation(): void
    {
        User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'Password123',
            'role' => 'wizard',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_the_single_sign_in_page_needs_no_portal(): void
    {
        User::factory()->donor()->create(['email' => 'donor@example.com', 'password' => 'Password123']);
        User::factory()->withRole(RoleName::BloodCenter)->create(['email' => 'center@example.com', 'password' => 'Password123']);
        User::factory()->withRole(RoleName::BloodBank)->create(['email' => 'hospital@example.com', 'password' => 'Password123']);

        foreach (['donor@example.com', 'center@example.com', 'hospital@example.com'] as $email) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'Password123'])
                ->assertOk()
                ->assertJsonPath('user.email', $email);
        }
    }

    public function test_an_administrator_is_sent_to_the_admin_sign_in(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create([
            'email' => 'admin@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'admin_sign_in_required');

        $this->assertSame(0, $admin->tokens()->count());

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123',
            'role' => 'admin',
        ])->assertOk();
    }

    public function test_an_administrator_with_another_role_is_still_sent_to_the_admin_sign_in(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->donor()->create([
            'email' => 'admin@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'admin_sign_in_required');

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_a_wrong_password_on_an_admin_account_still_answers_422(): void
    {
        User::factory()->withRole(RoleName::Admin)->create([
            'email' => 'admin@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'WrongPassword1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_an_account_with_no_role_is_refused_without_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'nobody@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'no_portal_access');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_legacy_applicant_on_the_single_page_is_told_its_facility_is_not_activated(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create([
            'email' => 'legacy@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'legacy@example.com',
            'password' => 'Password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_activated');

        $this->assertSame(0, $applicant->tokens()->count());
    }

    public function test_email_and_password_are_required(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_throttled_after_five_attempts_per_minute(): void
    {
        User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', [
                'email' => 'donor@example.com',
                'password' => 'WrongPassword1',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_the_login_response_never_exposes_the_password_hash(): void
    {
        User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ])->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('user'));
        $this->assertArrayNotHasKey('id', $response->json('user'));
    }
}
