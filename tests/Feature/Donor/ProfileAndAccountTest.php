<?php

namespace Tests\Feature\Donor;

use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\Donation;
use App\Models\DonorQrToken;
use App\Models\EligibilityScreening;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAndAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = User::factory()->donor()->create(['password' => 'Password123']);
    }

    public function test_a_donor_can_upload_an_avatar(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/avatar', [
                'avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'avatar_url']);

        $path = $this->donor->donorProfile->fresh()->profile_image_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_the_avatar_is_not_stored_in_the_public_webroot(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/avatar', [
                'avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg'),
            ])
            ->assertOk();

        $path = $this->donor->donorProfile->fresh()->profile_image_path;

        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_avatar_url_is_signed_and_serves_the_image(): void
    {
        Storage::fake('local');

        $url = $this->actingAs($this->donor)
            ->postJson('/api/donors/avatar', ['avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg')])
            ->assertOk()
            ->json('avatar_url');

        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
    }

    public function test_an_unsigned_avatar_request_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/avatar', ['avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg')])
            ->assertOk();

        $this->get('/api/donors/'.$this->donor->uuid.'/avatar')->assertForbidden();
    }

    public function test_uploading_a_non_image_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/avatar', [
                'avatar' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_an_oversized_avatar_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->donor)
            ->postJson('/api/donors/avatar', [
                'avatar' => UploadedFile::fake()->create('huge.jpg', 3000, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_a_donor_can_close_their_account(): void
    {
        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/account', ['password' => 'Password123'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertSoftDeleted('users', ['id' => $this->donor->id]);
    }

    public function test_closing_an_account_requires_the_correct_password(): void
    {
        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/account', ['password' => 'WrongPassword1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNotSoftDeleted('users', ['id' => $this->donor->id]);
    }

    public function test_closing_an_account_pseudonymises_personal_data(): void
    {
        $originalEmail = $this->donor->email;

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/account', ['password' => 'Password123'])
            ->assertOk();

        $user = User::withTrashed()->find($this->donor->id);

        $this->assertNotSame($originalEmail, $user->email);
        $this->assertSame('Deleted', $user->first_name);
        $this->assertNull($user->phone);
        $this->assertSame(AccountStatus::Deactivated, $user->account_status);
    }

    public function test_closing_an_account_retains_donation_records(): void
    {
        Donation::factory()->completedAt('2026-03-01')->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/account', ['password' => 'Password123'])
            ->assertOk();

        $this->assertSame(1, Donation::where('donor_id', $this->donor->id)->count());
    }

    public function test_closing_an_account_revokes_tokens_and_qr_codes(): void
    {
        $screening = EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);
        DonorQrToken::factory()->create([
            'donor_id' => $this->donor->id,
            'screening_id' => $screening->id,
        ]);
        $this->donor->createToken('donor-token');

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/account', ['password' => 'Password123'])
            ->assertOk();

        $this->assertSame(0, $this->donor->tokens()->count());
        $this->assertSame(0, DonorQrToken::usable()->count());
    }

    public function test_the_profile_endpoint_reports_the_next_eligible_date(): void
    {
        Donation::factory()->completedAt(now()->subDays(20)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/profile')
            ->assertOk()
            ->assertJsonPath('next_eligible_date', now()->subDays(20)->addDays(56)->toDateString())
            ->assertJsonPath('last_donation_date', now()->subDays(20)->toDateString());
    }

    public function test_the_dashboard_reports_a_derived_eligibility_status(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/dashboard')
            ->assertOk()
            ->assertJsonPath('eligibility_status', 'pending');

        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/dashboard')
            ->assertOk()
            ->assertJsonPath('eligibility_status', 'eligible');
    }

    public function test_the_dashboard_reports_expired_once_a_screening_lapses(): void
    {
        EligibilityScreening::factory()->expired()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/dashboard')
            ->assertOk()
            ->assertJsonPath('eligibility_status', 'expired');
    }

    public function test_the_dashboard_monthly_trend_works_without_mysql(): void
    {
        Donation::factory()->completedAt(now()->subMonths(2)->startOfMonth()->toDateString())
            ->create(['donor_id' => $this->donor->id]);

        $response = $this->actingAs($this->donor)
            ->getJson('/api/donors/dashboard')
            ->assertOk();

        $this->assertCount(12, $response->json('monthly_trend'));
        $this->assertSame(1, collect($response->json('monthly_trend'))->sum('count'));
    }

    public function test_support_contact_information_is_available(): void
    {
        $this->getJson('/api/support/contact-info')
            ->assertOk()
            ->assertJsonStructure(['hotline', 'hotline_label', 'email', 'hours']);
    }

    public function test_account_endpoints_reject_a_non_donor(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create(['password' => 'Password123']);

        $this->actingAs($admin)
            ->deleteJson('/api/donors/account', ['password' => 'Password123'])
            ->assertForbidden();
    }

    public function test_account_endpoints_reject_unauthenticated_callers(): void
    {
        $this->postJson('/api/donors/avatar')->assertUnauthorized();
        $this->deleteJson('/api/donors/account')->assertUnauthorized();
    }
}
