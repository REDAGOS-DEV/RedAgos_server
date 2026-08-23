<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Build the signed verification query string the SPA forwards to the API.
     */
    private function signedQuery(User $user, ?string $hash = null): string
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => $hash ?? sha1($user->getEmailForVerification()),
            ]
        );

        return substr($url, strpos($url, '?'));
    }

    public function test_a_signed_link_verifies_the_address_and_activates_the_account(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/email/verify'.$this->signedQuery($user))
            ->assertOk()
            ->assertJsonPath('message', 'Email address verified successfully.');

        $user->refresh();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertNotNull($user->activated_at);
    }

    public function test_verifying_an_already_verified_address_is_idempotent(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/email/verify'.$this->signedQuery($user))
            ->assertNoContent();
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/email/verify?id='.$user->id.'&hash='.sha1($user->email))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $query = $this->signedQuery($user);

        $this->postJson('/api/email/verify'.$query.'tampered')
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $query = $this->signedQuery($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/email/verify'.$query)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_signed_link_carrying_the_wrong_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/email/verify'.$this->signedQuery($user, sha1('someone-else@example.com')))
            ->assertForbidden()
            ->assertJsonPath('code', 'invalid_verification_link');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_signed_link_for_an_unknown_user_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $query = $this->signedQuery($user);
        $user->forceDelete();

        $this->postJson('/api/email/verify'.$query)
            ->assertForbidden()
            ->assertJsonPath('code', 'invalid_verification_link');
    }

    public function test_an_authenticated_user_can_request_another_verification_email(): void
    {
        $user = User::factory()->unverified()->create();
        Notification::fake();

        $this->actingAs($user)
            ->postJson('/api/email/verification-notification')
            ->assertNoContent();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_resending_to_an_already_verified_user_sends_nothing_but_still_succeeds(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $this->actingAs($user)
            ->postJson('/api/email/verification-notification')
            ->assertNoContent();

        Notification::assertNothingSent();
    }

    public function test_resending_requires_authentication(): void
    {
        $this->postJson('/api/email/verification-notification')->assertUnauthorized();
    }

    public function test_the_verification_link_points_at_the_frontend_application(): void
    {
        config(['app.frontend_url' => 'http://localhost:3000']);
        $user = User::factory()->unverified()->create();
        Notification::fake();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
            $actionUrl = $notification->toMail($user)->actionUrl;

            return str_starts_with($actionUrl, 'http://localhost:3000/auth/verify-email?')
                && str_contains($actionUrl, 'signature=')
                && str_contains($actionUrl, 'expires=');
        });
    }

    public function test_the_emailed_link_query_string_verifies_when_forwarded_to_the_api(): void
    {
        config(['app.frontend_url' => 'http://localhost:3000']);
        $user = User::factory()->unverified()->create();

        $actionUrl = (new VerifyEmailNotification)->toMail($user)->actionUrl;
        $query = substr($actionUrl, strpos($actionUrl, '?'));

        $this->postJson('/api/email/verify'.$query)->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}
