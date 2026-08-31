<?php

namespace Tests\Feature\Donor;

use App\Enums\IdentityStatus;
use App\Enums\ValidIdType;
use App\Models\DonorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class IdentityVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = User::factory()->donor()->create();
    }

    /**
     * A blood type code that already exists.
     *
     * Read off the seeded donor rather than created here: BloodTypeFactory picks
     * from a pool of eight and only tracks uniqueness within its own calls, so an
     * explicitly created row collides with whichever donor draws the same code.
     */
    private function existingBloodTypeCode(): string
    {
        return $this->donor->donorProfile->bloodType->code;
    }

    /**
     * Build a valid registration payload, overriding individual fields as needed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'blood_type' => $this->existingBloodTypeCode(),
            'gender' => 'male',
            'birth_date' => '1995-05-20',
            'address' => '123 Rizal Street, Davao City',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms_accepted' => true,
        ], $overrides);
    }

    /**
     * Build a valid identity submission, overriding individual fields as needed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function submission(array $overrides = []): array
    {
        return array_merge([
            'valid_id_type' => ValidIdType::DriversLicense->value,
            'valid_id_number' => 'N01-23-456789',
            'valid_id_image' => UploadedFile::fake()->create('id.jpg', 240, 'image/jpeg'),
        ], $overrides);
    }

    public function test_registration_stores_the_normalized_id_and_leaves_it_unsubmitted(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->registrationPayload([
            'valid_id_type' => ValidIdType::DriversLicense->value,
            'valid_id_number' => 'n01-23 456789',
        ]))->assertCreated();

        $profile = User::where('email', 'juan@example.com')->firstOrFail()->donorProfile;

        $this->assertSame('N0123456789', $profile->valid_id_number);
        $this->assertSame(ValidIdType::DriversLicense, $profile->valid_id_type);

        // A number with no document is not something an administrator can
        // review, so it must not land in the queue.
        $this->assertSame(IdentityStatus::Unsubmitted, $profile->identity_status);
    }

    public function test_registration_without_an_id_still_succeeds(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->registrationPayload())
            ->assertCreated();

        $profile = User::where('email', 'juan@example.com')->firstOrFail()->donorProfile;

        $this->assertNull($profile->valid_id_number);
        $this->assertNull($profile->valid_id_type);
    }

    public function test_registration_requires_the_id_type_and_number_together(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->registrationPayload([
            'valid_id_number' => 'N0123456789',
        ]))->assertUnprocessable()->assertJsonValidationErrors('valid_id_type');
    }

    public function test_two_spellings_of_one_id_number_collide(): void
    {
        Notification::fake();

        $this->postJson('/api/donors/register', $this->registrationPayload([
            'valid_id_type' => ValidIdType::DriversLicense->value,
            'valid_id_number' => 'P1234-5678',
        ]))->assertCreated();

        // Same ID, written the way a different person would type it. Without
        // normalisation this passes the unique rule and the same human ends up
        // holding two donor records.
        $this->postJson('/api/donors/register', $this->registrationPayload([
            'email' => 'maria@example.com',
            'phone' => '09171234568',
            'valid_id_type' => ValidIdType::DriversLicense->value,
            'valid_id_number' => 'p1234 5678',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_id_number');
    }

    public function test_a_punctuation_only_id_number_is_stored_as_null_and_does_not_collide(): void
    {
        Notification::fake();

        foreach ([['juan@example.com', '09171234567'], ['maria@example.com', '09171234568']] as [$email, $phone]) {
            $this->postJson('/api/donors/register', $this->registrationPayload([
                'email' => $email,
                'phone' => $phone,
                'valid_id_number' => '---',
            ]))->assertCreated();

            // Normalising to '' instead of null would make the second donor
            // collide with the first on a value neither of them supplied.
            $this->assertNull(User::where('email', $email)->firstOrFail()->donorProfile->valid_id_number);
        }
    }

    public function test_a_donor_submits_an_id_for_review(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/identity', $this->submission())
            ->assertOk()
            ->assertJsonPath('data.identity.status', IdentityStatus::Pending->value)
            ->assertJsonPath('data.identity.submission_version', 1);

        $profile = $this->donor->donorProfile->fresh();

        $this->assertSame('N0123456789', $profile->valid_id_number);
        $this->assertNotNull($profile->identity_submitted_at);
        Storage::disk('local')->assertExists($profile->valid_id_image_path);
    }

    public function test_the_document_is_not_stored_in_the_public_webroot(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/identity', $this->submission())
            ->assertOk();

        Storage::disk('public')->assertMissing(
            $this->donor->donorProfile->fresh()->valid_id_image_path
        );
    }

    public function test_resubmitting_replaces_the_document_and_leaves_one_file_behind(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)->postJson('/api/donors/identity', $this->submission())->assertOk();
        $firstPath = $this->donor->donorProfile->fresh()->valid_id_image_path;

        $this->actingAs($this->donor)
            ->postJson('/api/donors/identity', $this->submission([
                'valid_id_image' => UploadedFile::fake()->create('clearer.jpg', 300, 'image/jpeg'),
            ]))
            ->assertOk()
            ->assertJsonPath('data.identity.submission_version', 2);

        $secondPath = $this->donor->donorProfile->fresh()->valid_id_image_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_a_verified_id_cannot_be_replaced(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)->postJson('/api/donors/identity', $this->submission())->assertOk();

        $this->donor->donorProfile->fresh()->update(['identity_status' => IdentityStatus::Verified]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/identity', $this->submission([
                'valid_id_image' => UploadedFile::fake()->create('another.jpg', 200, 'image/jpeg'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_id_image');
    }

    public function test_a_failed_write_leaves_no_orphaned_document(): void
    {
        Storage::fake('local');

        // Fail inside the transaction, after the file has already been stored.
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'update "donor_profiles"') || str_contains($query->sql, 'update `donor_profiles`')) {
                throw new RuntimeException('write failed');
            }
        });

        try {
            $this->actingAs($this->donor)->postJson('/api/donors/identity', $this->submission());
        } catch (RuntimeException) {
            // The failure is the point; what matters is what it left on disk.
        }

        $this->assertSame(IdentityStatus::Unsubmitted, $this->donor->donorProfile->fresh()->identity_status);
        $this->assertEmpty(Storage::disk('local')->files('identity-documents'));
    }

    public function test_an_id_number_already_held_by_another_donor_is_a_field_error(): void
    {
        Storage::fake('local');

        DonorProfile::whereKey(User::factory()->donor()->create()->id)
            ->update(['valid_id_number' => 'N0123456789']);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/identity', $this->submission())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_id_number');
    }

    public function test_a_donor_may_resubmit_the_number_they_already_hold(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)->postJson('/api/donors/identity', $this->submission())->assertOk();

        // Same number, clearer photo. The unique rule has to ignore the donor's
        // own row or a corrected upload is impossible.
        $this->actingAs($this->donor)
            ->postJson('/api/donors/identity', $this->submission([
                'valid_id_image' => UploadedFile::fake()->create('clearer.jpg', 300, 'image/jpeg'),
            ]))
            ->assertOk();
    }

    public function test_closing_the_account_deletes_the_document(): void
    {
        Storage::fake('local');

        $donor = User::factory()->donor()->create(['password' => 'Password123']);

        $this->actingAs($donor)->postJson('/api/donors/identity', $this->submission())->assertOk();
        $path = $donor->donorProfile->fresh()->valid_id_image_path;

        $this->actingAs($donor)
            ->deleteJson('/api/donors/account', ['password' => 'Password123'])
            ->assertOk();

        Storage::disk('local')->assertMissing($path);
    }
}
