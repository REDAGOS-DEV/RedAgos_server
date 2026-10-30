<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sign in with Google (2026-10-30): the browser's ID token is checked with
 * Google and opens the account that already uses the address.
 */
class GoogleLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CLIENT_ID = 'test-client.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => self::CLIENT_ID]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function googleSays(string $email, array $overrides = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(array_merge([
                'iss' => 'https://accounts.google.com',
                'aud' => self::CLIENT_ID,
                'email' => $email,
                'email_verified' => 'true',
                'exp' => (string) now()->addHour()->timestamp,
            ], $overrides)),
        ]);
    }

    public function test_a_google_address_opens_the_account_that_uses_it(): void
    {
        $donor = User::factory()->donor()->create(['email' => 'juan@gmail.com']);
        $this->googleSays('Juan@gmail.com');

        $this->postJson('/api/login/google', ['credential' => 'id-token'])
            ->assertOk()
            ->assertJsonPath('user.email', 'juan@gmail.com')
            ->assertJsonStructure(['token']);

        $this->assertSame(1, $donor->tokens()->count());
        $this->assertSame('google', AuditLog::query()->where('action', 'auth.signed_in')->sole()->context['via'] ?? null);
    }

    public function test_an_administrator_signs_in_with_google_too(): void
    {
        User::factory()->withRole(RoleName::Admin)->create(['email' => 'admin@gmail.com']);
        $this->googleSays('admin@gmail.com');

        $this->postJson('/api/login/google', ['credential' => 'id-token'])->assertOk();
    }

    public function test_google_confirming_the_address_stands_in_for_the_emailed_link(): void
    {
        User::factory()->donor()->unverified()->create(['email' => 'new@gmail.com']);
        $this->googleSays('new@gmail.com');

        $this->postJson('/api/login/google', ['credential' => 'id-token'])->assertOk();
    }

    public function test_google_signs_nobody_up(): void
    {
        $this->googleSays('stranger@gmail.com');

        $this->postJson('/api/login/google', ['credential' => 'id-token'])
            ->assertNotFound()
            ->assertJsonPath('code', 'google_account_not_found');

        $this->assertDatabaseMissing('users', ['email' => 'stranger@gmail.com']);
    }

    public function test_a_token_for_another_app_or_an_unverified_address_is_refused(): void
    {
        User::factory()->donor()->create(['email' => 'juan@gmail.com']);

        $claims = [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'email' => 'juan@gmail.com',
            'email_verified' => 'true',
            'exp' => (string) now()->addHour()->timestamp,
        ];
        Http::fake(['oauth2.googleapis.com/*' => Http::sequence()
            ->push(array_merge($claims, ['aud' => 'someone-else.apps.googleusercontent.com']))
            ->push(array_merge($claims, ['email_verified' => 'false']))]);

        $this->postJson('/api/login/google', ['credential' => 'id-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_token_invalid');

        $this->postJson('/api/login/google', ['credential' => 'id-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_email_unverified');
    }

    public function test_a_deactivated_account_stays_shut(): void
    {
        $donor = User::factory()->donor()->create(['email' => 'juan@gmail.com']);
        $donor->forceFill(['account_status' => 'deactivated'])->save();
        $this->googleSays('juan@gmail.com');

        $this->postJson('/api/login/google', ['credential' => 'id-token'])->assertForbidden();
        $this->assertSame(0, $donor->tokens()->count());
    }

    public function test_it_says_so_when_google_is_not_set_up(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/login/google', ['credential' => 'id-token'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'google_not_configured');
    }
}
