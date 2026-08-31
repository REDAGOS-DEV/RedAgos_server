<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\BloodType;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DonorRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Idempotent: DonorProfileFactory also creates random blood types from an
        // eight-element pool, so an explicit create collides whenever they match.
        BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
    }

    /**
     * Build a valid registration payload, overriding individual fields as needed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'blood_type' => 'O+',
            'gender' => 'male',
            'birth_date' => '1995-05-20',
            'address' => '123 Rizal Street, Davao City',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms_accepted' => true,
        ], $overrides);
    }

    public function test_a_donor_can_register(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/donors/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['message', 'data' => ['user']])
            ->assertJsonPath('data.user.email', 'juan@example.com')
            ->assertJsonPath('data.user.roles', [RoleName::Donor->value]);

        $user = User::where('email', 'juan@example.com')->firstOrFail();

        $this->assertSame(AccountStatus::PendingVerification, $user->account_status);
        $this->assertNotNull($user->donorProfile);
        $this->assertSame('O+', $user->donorProfile->bloodType->code);
    }

    public function test_registration_does_not_issue_a_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertSame(0, User::where('email', 'juan@example.com')->firstOrFail()->tokens()->count());
    }

    public function test_registration_records_when_the_terms_were_accepted(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $this->assertNotNull(User::where('email', 'juan@example.com')->firstOrFail()->terms_accepted_at);
    }

    public function test_registration_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        Notification::assertSentTo(
            User::where('email', 'juan@example.com')->firstOrFail(),
            VerifyEmailNotification::class
        );
    }

    public function test_a_local_format_phone_number_is_normalised_to_e164(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload(['phone' => '09171234567']))
            ->assertCreated();

        $this->assertSame('+639171234567', User::where('email', 'juan@example.com')->firstOrFail()->phone);
    }

    public function test_a_63_prefixed_phone_number_is_normalised_to_e164(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload(['phone' => '639171234567']))
            ->assertCreated();

        $this->assertSame('+639171234567', User::where('email', 'juan@example.com')->firstOrFail()->phone);
    }

    /**
     * Documents current behaviour: RegisterDonorRequest's regex runs before
     * DonorService::normalizePhilippinePhone() strips separators, so a
     * human-formatted number is refused. Flagged as a follow-up, not changed here.
     */
    public function test_a_separator_formatted_phone_number_is_currently_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['phone' => '0917 123-4567']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_the_email_address_is_stored_in_lower_case(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload(['email' => 'Juan@Example.COM']))
            ->assertCreated();

        $this->assertNotNull(User::where('email', 'juan@example.com')->first());
    }

    public function test_the_password_is_hashed(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $user = User::where('email', 'juan@example.com')->firstOrFail();

        $this->assertNotSame('Password123', $user->password);
        $this->assertTrue(Hash::check('Password123', $user->password));
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $this->postJson('/api/donors/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_duplicate_phone_number_is_rejected(): void
    {
        User::factory()->create(['phone' => '+639171234567']);

        $this->postJson('/api/donors/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_non_philippine_phone_number_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['phone' => '+14155550123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_mismatched_password_confirmation_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload([
            'password_confirmation' => 'DifferentPassword123',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_an_applicant_under_eighteen_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload([
            'birth_date' => now()->subYears(17)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_an_applicant_who_turns_eighteen_today_is_accepted(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload([
            'birth_date' => now()->subYears(18)->toDateString(),
        ]))->assertCreated();
    }

    public function test_a_future_birth_date_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload([
            'birth_date' => now()->addYear()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_an_unknown_blood_type_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['blood_type' => 'Z+']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('blood_type');
    }

    public function test_declining_the_terms_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['terms_accepted' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');
    }

    public function test_an_invalid_gender_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['gender' => 'unknown']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('gender');
    }

    public function test_every_required_field_is_validated(): void
    {
        $this->postJson('/api/donors/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'email',
                'phone',
                'blood_type',
                'gender',
                'birth_date',
                'address',
                'password',
                'terms_accepted',
            ]);
    }

    public function test_a_failed_registration_leaves_no_partial_records(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['blood_type' => 'Z+']))
            ->assertStatus(422);

        $this->assertNull(User::where('email', 'juan@example.com')->first());
    }

    public function test_registration_is_throttled_after_five_attempts_per_minute(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/donors/register', $this->payload(['terms_accepted' => false]))
                ->assertStatus(422);
        }

        $this->postJson('/api/donors/register', $this->payload())
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_newly_registered_donor_cannot_log_in_before_verifying(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $this->postJson('/api/login', [
            'email' => 'juan@example.com',
            'password' => 'Password123',
            'role' => 'donor',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_a_newly_registered_donor_can_log_in_once_the_link_is_followed(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $donor = User::where('email', 'juan@example.com')->firstOrFail();
        $actionUrl = (new VerifyEmailNotification)->toMail($donor)->actionUrl;

        $this->postJson('/api/email/verify'.substr($actionUrl, strpos($actionUrl, '?')))
            ->assertOk();

        $this->postJson('/api/login', [
            'email' => 'juan@example.com',
            'password' => 'Password123',
            'role' => 'donor',
        ])->assertOk();
    }
}
