<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A facility's own logo, for the reports it prints.
 *
 * The Blood Center Admin's setting, kept on the private disk, shown to the browser by an
 * expiring signed link and to dompdf as inlined bytes.
 */
class FacilityLogoTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** A real 1x1 PNG, so dompdf has something it can decode. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->facility = Facility::factory()->approved()->create();
        $this->admin = User::factory()->bloodCenterAdmin($this->facility)->create();
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    public function test_an_admin_uploads_the_facility_logo(): void
    {
        $url = $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('logo_url');

        $path = $this->facility->fresh()->logo_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString('signature=', $url);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'center.logo_updated',
        ]);
    }

    public function test_the_logo_is_kept_off_the_public_disk(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_the_logo_deletes_the_old_file(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png('first.png')], ['Accept' => 'application/json'])
            ->assertOk();
        $first = $this->facility->fresh()->logo_path;

        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png('second.png')], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($this->facility->fresh()->logo_path);
    }

    public function test_only_png_and_jpg_up_to_two_megabytes_are_accepted(): void
    {
        $refused = [
            UploadedFile::fake()->create('logo.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('logo.webp', 50, 'image/webp'),
            UploadedFile::fake()->create('logo.png', 3000, 'image/png'),
        ];

        foreach ($refused as $file) {
            $this->actingAs($this->admin)
                ->post('/api/blood-center/facility/logo', ['logo' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('logo');
        }

        $this->assertNull($this->facility->fresh()->logo_path);
    }

    public function test_only_the_admin_may_change_it(): void
    {
        $issuance = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();
        $supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();

        foreach ([$issuance, $supervisor] as $user) {
            $this->actingAs($user)
                ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
                ->assertForbidden();

            $this->actingAs($user)->deleteJson('/api/blood-center/facility/logo')->assertForbidden();
        }
    }

    public function test_an_admin_changes_only_their_own_facility(): void
    {
        $other = Facility::factory()->approved()->create();

        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', [
                'logo' => $this->png(),
                'facility_id' => $other->id,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNotNull($this->facility->fresh()->logo_path);
        $this->assertNull($other->fresh()->logo_path);
    }

    public function test_removing_the_logo_deletes_the_file(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();
        $path = $this->facility->fresh()->logo_path;

        $this->actingAs($this->admin)
            ->deleteJson('/api/blood-center/facility/logo')
            ->assertOk()
            ->assertJsonPath('logo_url', null);

        $this->assertNull($this->facility->fresh()->logo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_signed_link_serves_the_logo_and_an_unsigned_one_does_not(): void
    {
        $url = $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->json('logo_url');

        $this->get($url)->assertOk();

        $this->get("/api/blood-center/facility/{$this->facility->id}/logo")->assertForbidden();
    }

    public function test_the_profile_carries_the_logo_link(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/blood-center/profile')
            ->assertOk()
            ->assertJsonPath('facility.logo_url', null);

        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertStringContainsString(
            'signature=',
            (string) $this->actingAs($this->admin)->getJson('/api/blood-center/profile')->json('facility.logo_url')
        );
    }

    // --- The stock report's left-hand logo (project owner, 2026-10-22) ---------

    public function test_an_admin_uploads_a_left_logo_and_replacing_it_deletes_the_old_file(): void
    {
        $url = $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/seal', ['logo' => $this->png('first.png')], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('seal_url');
        $first = $this->facility->fresh()->seal_path;

        $this->assertStringStartsWith('facility-seals/', $first);
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'action' => 'center.seal_updated']);

        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/seal', ['logo' => $this->png('second.png')], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($this->facility->fresh()->seal_path);

        // The facility logo is a separate slot and is untouched.
        $this->assertNull($this->facility->fresh()->logo_path);
    }

    public function test_removing_the_left_logo_deletes_the_file(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/seal', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();
        $path = $this->facility->fresh()->seal_path;

        $this->actingAs($this->admin)
            ->deleteJson('/api/blood-center/facility/seal')
            ->assertOk()
            ->assertJsonPath('seal_url', null);

        $this->assertNull($this->facility->fresh()->seal_path);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'action' => 'center.seal_removed']);
    }

    public function test_only_the_admin_may_change_the_left_logo_and_only_png_or_jpg(): void
    {
        $issuance = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();

        $this->actingAs($issuance)
            ->post('/api/blood-center/facility/seal', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->actingAs($issuance)->deleteJson('/api/blood-center/facility/seal')->assertForbidden();

        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/seal', ['logo' => UploadedFile::fake()->create('seal.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');

        $this->assertNull($this->facility->fresh()->seal_path);
    }

    public function test_the_left_logo_link_must_be_signed(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/seal', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        $this->get("/api/blood-center/facility/{$this->facility->id}/seal")->assertForbidden();
    }

    public function test_the_profile_and_the_stock_report_carry_the_left_logo(): void
    {
        $issuance = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();

        // None uploaded: null, and the report prints the DOH seal.
        $this->actingAs($this->admin)->getJson('/api/blood-center/profile')->assertJsonPath('facility.seal_url', null);
        $this->actingAs($issuance)->getJson('/api/blood-center/inventory/stock-report')->assertJsonPath('facility.seal_url', null);

        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/seal', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        $signed = fn (?string $url): bool => str_contains((string) $url, 'signature=');

        // A real request loads the account afresh; this one has its facility cached from before the upload.
        $issuance = $issuance->fresh();

        $this->actingAs($this->admin)->getJson('/api/blood-center/profile')->assertJsonPath('facility.seal_url', $signed);
        $this->actingAs($issuance)->getJson('/api/blood-center/inventory/stock-report')->assertJsonPath('facility.seal_url', $signed);

        $this->actingAs($issuance)
            ->get('/api/blood-center/inventory/stock-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_stock_report_pdf_prints_with_the_logo(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        // The Admin sets the logo; Issuance prints the sheet that carries it.
        $issuance = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();

        $this->actingAs($issuance)
            ->getJson('/api/blood-center/inventory/stock-report')
            ->assertOk()
            ->assertJsonPath('facility.logo_url', fn (?string $url): bool => str_contains((string) $url, 'signature='));

        $this->actingAs($issuance)
            ->get('/api/blood-center/inventory/stock-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
