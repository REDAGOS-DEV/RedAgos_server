<?php

namespace Tests\Feature\HospitalInventory;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A hospital staff member's own Settings (project owner, 2026-10-28): the
 * profile, photo and password, through the same staff profile the centre's
 * staff use, under /hospital.
 */
class HospitalProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $hospital;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->hospital = Facility::factory()->bloodBank()->approved()->create(['name' => 'Southern Hospital Blood Bank']);
        $this->staff = User::factory()->bloodBankStaff($this->hospital)->create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'password' => 'OldPassword1',
        ]);
    }

    public function test_staff_read_their_own_profile_and_hospital(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/hospital/profile')
            ->assertOk()
            ->assertJsonPath('profile.first_name', 'Ana')
            ->assertJsonPath('profile.email', $this->staff->email)
            ->assertJsonPath('facility.name', 'Southern Hospital Blood Bank')
            ->assertJsonStructure(['account' => ['username', 'account_status', 'created_at']]);
    }

    public function test_staff_change_their_own_name_number_and_title(): void
    {
        $this->actingAs($this->staff)
            ->patchJson('/api/hospital/profile', [
                'first_name' => 'Maria Ana',
                'last_name' => 'Reyes',
                'phone' => '09171234567',
                'credentials' => 'RMT',
            ])
            ->assertOk()
            ->assertJsonPath('profile.first_name', 'Maria Ana')
            ->assertJsonPath('profile.credentials', 'RMT');

        $this->assertSame('Maria Ana', $this->staff->fresh()->first_name);
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/hospital/password', [
                'current_password' => 'NotMyPassword1',
                'password' => 'NewPassword2',
                'password_confirmation' => 'NewPassword2',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_a_new_password_is_saved_and_every_session_ends(): void
    {
        $this->staff->createToken('another-device');

        $this->actingAs($this->staff)
            ->postJson('/api/hospital/password', [
                'current_password' => 'OldPassword1',
                'password' => 'NewPassword2',
                'password_confirmation' => 'NewPassword2',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('NewPassword2', $this->staff->fresh()->password));
        $this->assertSame(0, $this->staff->tokens()->count());
    }

    public function test_staff_upload_their_photo(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/hospital/profile/avatar', ['avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg')])
            ->assertOk()
            ->assertJsonStructure(['message', 'avatar_url']);

        Storage::disk('local')->assertExists($this->staff->fresh()->profile_image_path);
    }

    public function test_each_portal_keeps_to_its_own_profile_route(): void
    {
        $centreStaff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($centreStaff)->getJson('/api/hospital/profile')->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/blood-center/profile')->assertForbidden();
    }
}
