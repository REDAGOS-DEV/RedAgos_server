<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
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
        User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => '  DONOR@Example.com ',
            'password' => 'Password123',
        ])->assertOk();
    }

    public function test_login_reports_when_the_email_address_is_still_unverified(): void
    {
        User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'unverified@example.com',
            'password' => 'Password123',
        ])->assertOk()->assertJsonPath('must_verify_email', true);
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

    public function test_a_pending_verification_account_may_still_log_in(): void
    {
        User::factory()->unverified()->donor()->create([
            'email' => 'pending@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'pending@example.com',
            'password' => 'Password123',
            'role' => 'donor',
        ])->assertOk()->assertJsonPath('must_verify_email', true);
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

    public function test_omitting_the_role_skips_the_portal_check(): void
    {
        User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ])->assertOk();
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
