<?php

namespace Tests\Feature;

use App\Enums\ValidIdType;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded files go to the disk UPLOADS_DISK names (2026-10-29).
 *
 * Deployed on Laravel Cloud they live in a private bucket, because the app's
 * own disk is wiped on every deploy. What is locked in: pointed at another
 * disk, a staff photo, a donor's identity document and a facility logo are
 * written there and not to `local`, and read back from it.
 */
class UploadsDiskTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.uploads' => 'bucket']);

        Storage::fake('bucket');
        Storage::fake('local');
    }

    public function test_a_staff_photo_is_stored_on_and_served_from_the_configured_disk(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $url = $this->actingAs($staff)
            ->postJson('/api/blood-center/profile/avatar', ['avatar' => UploadedFile::fake()->create('me.jpg', 120, 'image/jpeg')])
            ->assertOk()
            ->json('avatar_url');

        $path = $staff->fresh()->profile_image_path;

        Storage::disk('bucket')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->get($url)->assertOk();
    }

    public function test_an_identity_document_is_stored_on_and_served_from_the_configured_disk(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)
            ->postJson('/api/donors/identity', [
                'valid_id_type' => ValidIdType::DriversLicense->value,
                'valid_id_number' => 'N01-23-456789',
                'valid_id_image' => UploadedFile::fake()->create('id.jpg', 240, 'image/jpeg'),
            ])
            ->assertOk();

        $path = $donor->donorProfile->fresh()->valid_id_image_path;

        Storage::disk('bucket')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->actingAs($donor)->get("/api/donors/{$donor->uuid}/identity-image")->assertOk();
    }

    public function test_a_facility_logo_is_stored_on_the_configured_disk(): void
    {
        $facility = Facility::factory()->approved()->create();
        $admin = User::factory()->bloodCenterAdmin($facility)->create();

        $url = $this->actingAs($admin)
            ->post('/api/blood-center/facility/logo', [
                'logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG)),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('logo_url');

        $path = $facility->fresh()->logo_path;

        Storage::disk('bucket')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->get($url)->assertOk();
    }
}
