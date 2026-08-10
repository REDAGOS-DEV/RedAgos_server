<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_reset_link_is_emailed_to_a_registered_address(): void
    {
        $user = User::factory()->create(['email' => 'donor@example.com']);
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'donor@example.com'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_the_reset_link_points_at_the_frontend_application(): void
    {
        config(['app.frontend_url' => 'http://localhost:3000']);
        $user = User::factory()->create(['email' => 'donor@example.com']);
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'donor@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $actionUrl = $notification->toMail($user)->actionUrl;

            return str_starts_with($actionUrl, 'http://localhost:3000/auth/reset-password?')
                && str_contains($actionUrl, 'token=')
                && str_contains($actionUrl, 'email=');
        });
    }

    public function test_an_unknown_address_returns_the_same_response_and_sends_nothing(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If this email is registered, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_the_forgot_password_response_is_identical_for_known_and_unknown_addresses(): void
    {
        User::factory()->create(['email' => 'donor@example.com']);
        Notification::fake();

        $known = $this->postJson('/api/forgot-password', ['email' => 'donor@example.com']);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'OldPassword123',
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk()->assertJsonStructure(['message']);

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_resetting_a_password_revokes_every_existing_token(): void
    {
        $user = User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'OldPassword123',
        ]);
        $user->createToken('donor-token');
        $user->createToken('donor-token');
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_the_new_password_can_be_used_to_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'OldPassword123',
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
        ])->assertOk();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'OldPassword123',
        ]);

        $this->postJson('/api/reset-password', [
            'token' => 'this-token-was-never-issued',
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'OldPassword123',
        ]);
        $token = Password::createToken($user);

        $payload = [
            'token' => $token,
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ];

        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->postJson('/api/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'donor@example.com',
            'password' => 'OldPassword123',
        ]);
        $token = Password::createToken($user);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_a_token_issued_for_another_account_is_rejected(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'OldPassword123']);
        $token = Password::createToken($owner);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'victim@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('OldPassword123', User::where('email', 'victim@example.com')->first()->password));
    }

    public function test_the_new_password_must_meet_the_strength_policy(): void
    {
        $user = User::factory()->create(['email' => 'donor@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'donor@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $user = User::factory()->create(['email' => 'donor@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'donor@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'DifferentPassword123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
