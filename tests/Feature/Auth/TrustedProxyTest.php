<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Behind the host's proxy (2026-10-29: Laravel Cloud, behind Cloudflare).
 *
 * What is locked in: the visitor's own address, from X-Forwarded-For, is the
 * one the sign-in throttle counts, so one visitor's failed attempts do not
 * lock out everybody else arriving through the same proxy; and a request the
 * proxy received over HTTPS is treated as HTTPS.
 */
class TrustedProxyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PROXY = '10.0.0.5';

    private function signInFrom(string $visitor, string $password): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeader('X-Forwarded-For', $visitor)
            ->postJson('/api/login', [
                'email' => 'donor@example.com',
                'password' => $password,
            ]);
    }

    public function test_the_sign_in_throttle_counts_each_visitor_behind_the_proxy_apart(): void
    {
        User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->signInFrom('203.0.113.10', 'WrongPassword1')->assertStatus(422);
        }

        $this->signInFrom('203.0.113.10', 'Password123')->assertStatus(429);
        $this->signInFrom('198.51.100.20', 'Password123')->assertOk();
    }

    public function test_a_request_the_proxy_received_over_https_is_https(): void
    {
        Route::get('/proxy-probe', fn () => [
            'secure' => request()->secure(),
            'ip' => request()->ip(),
            'url' => url('/somewhere'),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.10',
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('/proxy-probe')
            ->assertOk()
            ->assertExactJson([
                'secure' => true,
                'ip' => '203.0.113.10',
                'url' => 'https://localhost/somewhere',
            ]);
    }
}
