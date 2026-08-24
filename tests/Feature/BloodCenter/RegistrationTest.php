<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'center_name' => 'Davao Regional Blood Center',
            'doh_license_number' => 'DOH-BC-2026-00412',
            'contact_first_name' => 'Maria',
            'contact_last_name' => 'Santos',
            'position' => 'Medical Technologist',
            'email' => 'maria.santos@drbc.ph',
            'phone' => '09171234567',
            'address' => 'Quirino Ave, Davao City',
            'description' => 'Regional blood collection and processing centre.',
            'password' => 'SecurePass1',
            'password_confirmation' => 'SecurePass1',
        ], $overrides);
    }

    public function test_a_blood_center_can_register(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.facility.status', FacilityStatus::PendingApproval->value)
            ->assertJsonPath('data.user.email', 'maria.santos@drbc.ph')
            ->assertJsonPath('data.user.account_status', AccountStatus::PendingVerification->value);

        $this->assertDatabaseHas('facilities', [
            'name' => 'Davao Regional Blood Center',
            'doh_license_number' => 'DOH-BC-2026-00412',
            'status' => FacilityStatus::PendingApproval->value,
        ]);
    }

    public function test_registration_attaches_no_role(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload())->assertCreated();

        $user = User::where('email', 'maria.santos@drbc.ph')->firstOrFail();

        $this->assertFalse($user->hasRole(RoleName::BloodCenter));
        $this->assertCount(0, $user->roles);
    }

    public function test_registration_issues_no_token(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload())
            ->assertCreated()
            ->assertJsonMissingPath('token');
    }

    public function test_the_applicant_is_recorded_as_the_registration_contact(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload())->assertCreated();

        $user = User::where('email', 'maria.santos@drbc.ph')->firstOrFail();
        $facility = Facility::where('doh_license_number', 'DOH-BC-2026-00412')->firstOrFail();

        $this->assertSame($user->id, $facility->registration_contact_user_id);
        $this->assertSame($facility->id, $user->facility_id);
    }

    public function test_the_facility_type_is_resolved_server_side(): void
    {
        $bloodBank = FacilityType::firstOrCreate(['name' => 'blood_bank']);

        // A client-supplied facility_type_id must be ignored entirely, so an
        // applicant cannot file itself under a different organisation type.
        $this->postJson('/api/blood-center/register', $this->payload([
            'facility_type_id' => $bloodBank->id,
        ]))->assertCreated();

        $facility = Facility::where('doh_license_number', 'DOH-BC-2026-00412')->firstOrFail();

        $this->assertSame('blood_center', $facility->facilityType->name);
    }

    public function test_registration_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/blood-center/register', $this->payload())->assertCreated();

        Notification::assertSentTo(
            User::where('email', 'maria.santos@drbc.ph')->firstOrFail(),
            VerifyEmailNotification::class
        );
    }

    public function test_the_phone_number_is_normalised_to_e164(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload(['phone' => '09171234567']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '+639171234567']);
    }

    public function test_a_separator_formatted_phone_number_is_accepted(): void
    {
        // Normalisation runs in prepareForValidation, so separators reach the
        // same stored value rather than being rejected by the regex.
        $this->postJson('/api/blood-center/register', $this->payload(['phone' => '0917-123-4567']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '+639171234567']);
    }

    public function test_the_email_address_is_stored_in_lower_case(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload(['email' => 'Maria.Santos@DRBC.ph']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'maria.santos@drbc.ph']);
    }

    public function test_a_duplicate_doh_license_is_rejected(): void
    {
        Facility::factory()->create(['doh_license_number' => 'DOH-BC-2026-00412']);

        $this->postJson('/api/blood-center/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('doh_license_number');
    }

    public function test_a_duplicate_center_name_is_rejected(): void
    {
        Facility::factory()->create(['name' => 'Davao Regional Blood Center']);

        $this->postJson('/api/blood-center/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_name');
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'maria.santos@drbc.ph']);

        $this->postJson('/api/blood-center/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_duplicate_phone_number_is_rejected_after_normalisation(): void
    {
        // Stored as E.164, submitted in local format. Without normalisation
        // before validation these would not match and the duplicate would only
        // surface as a database error.
        User::factory()->create(['phone' => '+639171234567']);

        $this->postJson('/api/blood-center/register', $this->payload(['phone' => '09171234567']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_non_philippine_phone_number_is_rejected(): void
    {
        $this->postJson('/api/blood-center/register', $this->payload(['phone' => '+14155550123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_every_required_field_is_validated(): void
    {
        $this->postJson('/api/blood-center/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'center_name', 'doh_license_number', 'contact_first_name',
                'contact_last_name', 'position', 'email', 'phone', 'address', 'password',
            ]);
    }

    public function test_a_failed_registration_leaves_no_partial_records(): void
    {
        User::factory()->create(['email' => 'maria.santos@drbc.ph']);

        $this->postJson('/api/blood-center/register', $this->payload())->assertStatus(422);

        $this->assertDatabaseMissing('facilities', ['doh_license_number' => 'DOH-BC-2026-00412']);
    }

    public function test_registration_is_throttled_after_five_attempts_per_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/blood-center/register', $this->payload([
                'center_name' => 'Center '.$attempt,
                'doh_license_number' => 'DOH-BC-'.$attempt,
                'email' => "center{$attempt}@example.ph",
                'phone' => '0917123456'.$attempt,
            ]));
        }

        $this->postJson('/api/blood-center/register', $this->payload([
            'center_name' => 'Center Six',
            'doh_license_number' => 'DOH-BC-6',
            'email' => 'center6@example.ph',
            'phone' => '09171234566',
        ]))->assertStatus(429);
    }
}
