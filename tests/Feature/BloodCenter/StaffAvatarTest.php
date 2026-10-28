<?php

namespace Tests\Feature\BloodCenter;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A blood-centre staff member's own profile photo (2026-10-26).
 *
 * What is locked in: the photo is stored on the private disk, the session
 * payload and the profile carry a signed link to it (so the portal header
 * shows it), a replaced photo's file is removed, an unsigned or re-pointed
 * link is refused, a non-image is refused, and a donor's photo still reads
 * from the donor profile.
 */
class StaffAvatarTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = '/api/blood-center/profile/avatar';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->staff = User::factory()->bloodCenterStaff()->create();
    }

    private function upload(?User $as = null): string
    {
        return $this->actingAs($as ?? $this->staff)
            ->postJson(self::URL, ['avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg')])
            ->assertOk()
            ->assertJsonStructure(['message', 'avatar_url'])
            ->json('avatar_url');
    }

    public function test_staff_can_upload_their_photo_to_the_private_disk(): void
    {
        $this->upload();

        $path = $this->staff->fresh()->profile_image_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_session_and_the_profile_carry_a_signed_link_that_serves_it(): void
    {
        $this->upload();

        $fromSession = $this->actingAs($this->staff)->getJson('/api/user')->json('data.avatar_url');
        $fromProfile = $this->actingAs($this->staff)->getJson('/api/blood-center/profile')->json('profile.avatar_url');

        $this->assertStringContainsString('signature=', $fromSession);
        $this->assertStringContainsString('signature=', $fromProfile);
        $this->get($fromSession)->assertOk();
    }

    public function test_without_a_photo_the_link_is_null(): void
    {
        $this->actingAs($this->staff)->getJson('/api/user')->assertJsonPath('data.avatar_url', null);
        $this->actingAs($this->staff)->getJson('/api/blood-center/profile')->assertJsonPath('profile.avatar_url', null);
    }

    public function test_a_new_photo_replaces_the_old_file(): void
    {
        $this->upload();
        $first = $this->staff->fresh()->profile_image_path;

        $this->upload();
        $second = $this->staff->fresh()->profile_image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_an_unsigned_or_re_pointed_link_is_refused(): void
    {
        $url = $this->upload();
        $other = User::factory()->bloodCenterStaff()->create();

        $this->get('/api/staff/'.$this->staff->uuid.'/avatar')->assertForbidden();
        $this->get(str_replace($this->staff->uuid, $other->uuid, $url))->assertForbidden();
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->postJson(self::URL, ['avatar' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($this->staff->fresh()->profile_image_path);
    }

    public function test_only_blood_centre_accounts_upload_here(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)
            ->postJson(self::URL, ['avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg')])
            ->assertForbidden();
    }

    public function test_a_donors_photo_still_reads_from_the_donor_profile(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)
            ->postJson('/api/donors/avatar', ['avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg')])
            ->assertOk();

        $url = $this->actingAs($donor)->getJson('/api/user')->json('data.avatar_url');

        $this->assertStringContainsString('/donors/', $url);
        $this->get($url)->assertOk();
    }
}
