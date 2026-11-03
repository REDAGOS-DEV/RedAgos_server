<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * An address is verified with a six-digit code mailed to it (2026-11-03), typed
 * back into the portal, in place of the signed link.
 */
class EmailVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REFUSAL = 'That code is incorrect or has expired.';

    private function verify(string $email, string $code)
    {
        return $this->postJson('/api/email/verify', ['email' => $email, 'code' => $code]);
    }

    /** A six-digit code that is not the one given. */
    private function wrong(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function test_the_code_verifies_the_address_and_activates_the_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $code = EmailVerificationCode::issue($user);

        $this->verify('pending@example.com', $code)
            ->assertOk()
            ->assertJsonPath('message', 'Email address verified successfully.');

        $user->refresh();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertNotNull($user->activated_at);
    }

    public function test_the_address_is_matched_whatever_its_case_or_spacing(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $code = EmailVerificationCode::issue($user);

        $this->verify('  PENDING@Example.com ', $code)->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_code_works_once(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $code = EmailVerificationCode::issue($user);

        $this->verify('pending@example.com', $code)->assertOk();

        $this->assertNull($user->fresh()->emailVerificationCode);
        $this->verify('pending@example.com', $code)
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', self::REFUSAL);
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $code = EmailVerificationCode::issue($user);

        $this->verify('pending@example.com', $this->wrong($code))
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', self::REFUSAL);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame(1, $user->emailVerificationCode->attempts);
    }

    public function test_a_code_stops_working_after_five_wrong_tries(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $code = EmailVerificationCode::issue($user);

        foreach (range(1, EmailVerificationCode::MAX_ATTEMPTS) as $try) {
            $this->verify('pending@example.com', $this->wrong($code))->assertStatus(422);
        }

        // Even the right code: a sixth guess is never compared.
        $this->verify('pending@example.com', $code)
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', self::REFUSAL);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $code = EmailVerificationCode::issue($user);

        $this->travel(11)->minutes();

        $this->verify('pending@example.com', $code)->assertStatus(422);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_only_the_latest_code_works(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $first = EmailVerificationCode::issue($user);
        $latest = EmailVerificationCode::issue($user);

        if ($first !== $latest) {
            $this->verify('pending@example.com', $first)->assertStatus(422);
        }

        $this->verify('pending@example.com', $latest)->assertOk();
    }

    public function test_a_code_cannot_verify_an_address_changed_after_it_was_sent(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
        $code = EmailVerificationCode::issue($user);

        $user->forceFill(['email' => 'new@example.com'])->save();

        $this->verify('new@example.com', $code)->assertStatus(422);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_every_failure_gives_the_same_answer(): void
    {
        $verified = User::factory()->create(['email' => 'verified@example.com']);
        $code = EmailVerificationCode::issue($verified);
        $pending = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $pendingCode = EmailVerificationCode::issue($pending);

        $answers = [
            $this->verify('nobody@example.com', '123456'),
            $this->verify('verified@example.com', $code),
            $this->verify('pending@example.com', $this->wrong($pendingCode)),
        ];

        foreach ($answers as $answer) {
            $answer->assertStatus(422)->assertExactJson([
                'message' => self::REFUSAL,
                'errors' => ['code' => [self::REFUSAL]],
            ]);
        }
    }

    public function test_the_code_must_be_six_digits(): void
    {
        $this->verify('pending@example.com', '12345')
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->verify('pending@example.com', '12345a')
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_only_a_hash_of_the_code_is_stored(): void
    {
        $user = User::factory()->unverified()->create();
        $code = EmailVerificationCode::issue($user);

        $stored = $user->emailVerificationCode;

        $this->assertNotSame($code, $stored->code_hash);
        $this->assertStringNotContainsString($code, json_encode($stored->toArray()));
    }

    public function test_the_mail_carries_the_code(): void
    {
        $user = User::factory()->unverified()->create();
        Notification::fake();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user) {
            $html = (string) $notification->toMail($user)->render();

            return preg_match('/^\d{6}$/', $notification->code) === 1
                && str_contains($html, $notification->code)
                && ! str_contains($html, '/auth/verify-email');
        });
    }

    public function test_an_authenticated_user_can_request_another_code(): void
    {
        $user = User::factory()->unverified()->create();
        Notification::fake();

        $this->actingAs($user)
            ->postJson('/api/email/verification-notification')
            ->assertNoContent();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_a_new_code_replaces_the_one_before_it(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        Notification::fake();

        $this->actingAs($user)->postJson('/api/email/verification-notification')->assertNoContent();
        $this->actingAs($user)->postJson('/api/email/verification-notification')->assertNoContent();

        $codes = Notification::sent($user, VerifyEmailNotification::class)->map->code;
        $this->assertCount(2, $codes);
        $this->assertSame(1, EmailVerificationCode::query()->where('user_id', $user->id)->count());

        $this->verify('pending@example.com', $codes->last())->assertOk();
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

    public function test_a_guest_can_request_another_code_by_address(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        Notification::fake();

        $this->postJson('/api/email/resend-verification', ['email' => '  PENDING@Example.com '])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If that address is registered and still unverified, a new code is on its way.'
            );

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_a_guest_resend_reveals_nothing_about_an_unknown_or_verified_address(): void
    {
        User::factory()->create(['email' => 'verified@example.com']);
        Notification::fake();

        $unknown = $this->postJson('/api/email/resend-verification', ['email' => 'nobody@example.com'])
            ->assertOk();

        $verified = $this->postJson('/api/email/resend-verification', ['email' => 'verified@example.com'])
            ->assertOk();

        $this->assertSame($unknown->json('message'), $verified->json('message'));
        Notification::assertNothingSent();
    }

    public function test_a_guest_resend_sends_nothing_for_a_suspended_account(): void
    {
        User::factory()->unverified()->suspended()->create(['email' => 'suspended@example.com']);
        Notification::fake();

        $this->postJson('/api/email/resend-verification', ['email' => 'suspended@example.com'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_guest_resend_requires_a_valid_email_address(): void
    {
        $this->postJson('/api/email/resend-verification', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_guest_resend_is_throttled(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/email/resend-verification', ['email' => 'pending@example.com'])
                ->assertOk();
        }

        $this->postJson('/api/email/resend-verification', ['email' => 'pending@example.com'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_verifying_is_throttled(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->verify('pending@example.com', '000000')->assertStatus(422);
        }

        $this->verify('pending@example.com', '000000')->assertStatus(429);
    }
}
