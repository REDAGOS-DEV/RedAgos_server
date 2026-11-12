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
            'valid_id_type' => 'philsys',
            'valid_id_number' => '1234567890123456',
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

    public function test_a_name_with_an_emoji_or_a_digit_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload([
            'first_name' => 'Juan 😀',
            'last_name' => 'Dela Cruz 2',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);
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

    public function test_a_donor_who_does_not_know_their_blood_type_can_register_without_one(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload(['blood_type' => null]))
            ->assertCreated();

        $donor = User::where('email', 'juan@example.com')->firstOrFail();

        // Null, not a default. The laboratory fills this in from the first
        // cleared donation; anything stored here now would be a guess.
        $this->assertNull($donor->donorProfile->blood_type_id);
    }

    public function test_an_invalid_blood_type_is_still_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['blood_type' => 'Z+']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('blood_type');
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
                'gender',
                'birth_date',
                'address',
                'valid_id_type',
                'valid_id_number',
                'password',
                'terms_accepted',
            ]);
    }

    public function test_a_valid_id_is_required(): void
    {
        $this->postJson('/api/donors/register', $this->payload([
            'valid_id_type' => null,
            'valid_id_number' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'valid_id_type' => 'Please choose your valid ID.',
                'valid_id_number' => 'Please enter the number on your ID.',
            ]);

        $this->assertNull(User::where('email', 'juan@example.com')->first());
    }

    public function test_an_id_type_without_its_number_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['valid_id_number' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('valid_id_number')
            ->assertJsonMissingValidationErrors('valid_id_type');
    }

    public function test_an_id_number_without_its_type_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload(['valid_id_type' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('valid_id_type');
    }

    public function test_an_id_number_in_the_wrong_shape_for_its_type_is_rejected(): void
    {
        $this->postJson('/api/donors/register', $this->payload([
            'valid_id_type' => 'philsys',
            'valid_id_number' => '123456789012',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_id_number' => 'PhilSys number: 16 digits, e.g. 1234567890123456.']);
    }

    public function test_an_id_type_with_no_fixed_numbering_takes_the_number_as_printed(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload([
            'valid_id_type' => 'postal_id',
            'valid_id_number' => 'PRN 100-1234-5678',
        ]))->assertCreated();
    }

    public function test_registration_takes_no_id_photo(): void
    {
        Notification::fake();

        // The photo is added later from the profile; a registration carries
        // the type and number only.
        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $profile = User::where('email', 'juan@example.com')->firstOrFail()->donorProfile;

        $this->assertSame('1234567890123456', $profile->valid_id_number);
        $this->assertNull($profile->valid_id_image_path);
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

    public function test_a_newly_registered_donor_can_log_in_once_the_code_is_entered(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $donor = User::where('email', 'juan@example.com')->firstOrFail();
        $code = null;
        Notification::assertSentTo($donor, VerifyEmailNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/email/verify', ['email' => 'juan@example.com', 'code' => $code])
            ->assertOk();

        $this->postJson('/api/login', [
            'email' => 'juan@example.com',
            'password' => 'Password123',
            'role' => 'donor',
        ])->assertOk();
    }

    public function test_registration_does_not_ask_for_the_questionnaire_personal_data(): void
    {
        Notification::fake();

        // Section I-A belongs to the profile, not to signing up. A donor who
        // registers without it is perfectly valid; the blood centre's
        // questionnaire prints what is absent as "Not provided".
        $this->postJson('/api/donors/register', $this->payload())->assertCreated();

        $profile = User::where('email', 'juan@example.com')->firstOrFail()->donorProfile;

        $this->assertNull($profile->civil_status);
        $this->assertNull($profile->occupation);
        $this->assertNull($profile->nationality);
    }

    // --- Continue on each step: POST /donors/register/check (2026-11-11) ---

    public function test_step_one_says_the_address_is_taken_before_the_rest_is_filled_in(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $this->postJson('/api/donors/register/check', [
            'step' => 1,
            'email' => 'Juan@Example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'This email address is already registered.'])
            ->assertJsonMissingValidationErrors(['first_name', 'phone', 'valid_id_type', 'terms_accepted']);
    }

    public function test_a_free_address_and_good_password_pass_step_one_and_store_nothing(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register/check', [
            'step' => 1,
            'email' => 'juan@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertNoContent();

        $this->assertNull(User::where('email', 'juan@example.com')->first());
        Notification::assertNothingSent();
    }

    public function test_step_one_applies_the_password_rules(): void
    {
        $this->postJson('/api/donors/register/check', [
            'step' => 1,
            'email' => 'juan@example.com',
            'password' => 'password',
            'password_confirmation' => 'different',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_step_two_checks_the_age_and_takes_an_unknown_blood_type(): void
    {
        $step = [
            'step' => 2,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'gender' => 'male',
            'blood_type' => null,
        ];

        $this->postJson('/api/donors/register/check', [...$step, 'birth_date' => now()->subYears(17)->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');

        $this->postJson('/api/donors/register/check', [...$step, 'birth_date' => '1995-05-20'])
            ->assertNoContent();
    }

    public function test_step_three_checks_the_phone_and_the_id(): void
    {
        $step = [
            'step' => 3,
            'phone' => '09171234567',
            'address' => '123 Rizal Street',
            'valid_id_type' => 'philsys',
            'valid_id_number' => '1234567890123456',
            'terms_accepted' => true,
        ];

        $this->postJson('/api/donors/register/check', [...$step, 'valid_id_number' => '123456789012'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_id_number' => 'PhilSys number: 16 digits, e.g. 1234567890123456.']);

        // Looked up as stored (+63…), as the registration looks it up.
        User::factory()->create(['phone' => '+639171234567']);

        $this->postJson('/api/donors/register/check', $step)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone' => 'This phone number is already registered.']);
    }

    public function test_an_unknown_step_is_refused(): void
    {
        $this->postJson('/api/donors/register/check', ['step' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('step');

        $this->postJson('/api/donors/register/check', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('step');
    }

    public function test_the_step_check_is_throttled_more_loosely_than_registration(): void
    {
        foreach (range(1, 30) as $attempt) {
            $this->postJson('/api/donors/register/check', ['step' => 1])->assertStatus(422);
        }

        $this->postJson('/api/donors/register/check', ['step' => 1])->assertStatus(429);
    }
}
