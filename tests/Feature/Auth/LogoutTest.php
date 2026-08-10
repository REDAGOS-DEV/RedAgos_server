<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_logout_revokes_the_token_used_for_the_request(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_a_revoked_token_can_no_longer_reach_a_protected_route(): void
    {
        $user = User::factory()->donor()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();

        // The guard caches the resolved user for the lifetime of the test
        // application, so it is reset here to force a genuine re-authentication.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_logout_only_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $phoneToken = $user->createToken('donor-token')->plainTextToken;
        $user->createToken('donor-token');

        $this->withToken($phoneToken)->postJson('/api/logout')->assertNoContent();

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('donor-token')->plainTextToken;
        $user->createToken('donor-token');
        $user->createToken('donor-token');

        $this->withToken($token)->postJson('/api/logout-all')->assertNoContent();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_logout_all_requires_authentication(): void
    {
        $this->postJson('/api/logout-all')->assertUnauthorized();
    }

    public function test_the_current_user_endpoint_returns_a_whitelisted_payload(): void
    {
        $user = User::factory()->donor()->create(['email' => 'donor@example.com']);

        $response = $this->actingAs($user)->getJson('/api/user')->assertOk();

        $response->assertJsonPath('data.email', 'donor@example.com')
            ->assertJsonPath('data.roles', ['donor']);

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('id', $response->json('data'));
    }

    public function test_the_current_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
