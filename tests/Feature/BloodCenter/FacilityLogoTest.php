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
 * A supervisor's setting, kept on the private disk, shown to the browser by an
 * expiring signed link and to dompdf as inlined bytes.
 */
class FacilityLogoTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** A real 1x1 PNG, so dompdf has something it can decode. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Facility $facility;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->facility = Facility::factory()->approved()->create();
        $this->supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    public function test_a_supervisor_uploads_the_facility_logo(): void
    {
        $url = $this->actingAs($this->supervisor)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('logo_url');

        $path = $this->facility->fresh()->logo_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString('signature=', $url);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->supervisor->id,
            'action' => 'center.logo_updated',
        ]);
    }

    public function test_the_logo_is_kept_off_the_public_disk(): void
    {
        Storage::fake('public');

        $this->actingAs($this->supervisor)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_the_logo_deletes_the_old_file(): void
    {
        $this->actingAs($this->supervisor)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png('first.png')], ['Accept' => 'application/json'])
            ->assertOk();
        $first = $this->facility->fresh()->logo_path;

        $this->actingAs($this->supervisor)
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
            $this->actingAs($this->supervisor)
                ->post('/api/blood-center/facility/logo', ['logo' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('logo');
        }

        $this->assertNull($this->facility->fresh()->logo_path);
    }

    public function test_only_a_supervisor_may_change_it(): void
    {
        $issuance = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();

        $this->actingAs($issuance)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($issuance)->deleteJson('/api/blood-center/facility/logo')->assertForbidden();
    }

    public function test_a_supervisor_changes_only_their_own_facility(): void
    {
        $other = Facility::factory()->approved()->create();

        $this->actingAs($this->supervisor)
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
        $this->actingAs($this->supervisor)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();
        $path = $this->facility->fresh()->logo_path;

        $this->actingAs($this->supervisor)
            ->deleteJson('/api/blood-center/facility/logo')
            ->assertOk()
            ->assertJsonPath('logo_url', null);

        $this->assertNull($this->facility->fresh()->logo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_signed_link_serves_the_logo_and_an_unsigned_one_does_not(): void
    {
        $url = $this->actingAs($this->supervisor)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->json('logo_url');

        $this->get($url)->assertOk();

        $this->get("/api/blood-center/facility/{$this->facility->id}/logo")->assertForbidden();
    }

    public function test_the_profile_carries_the_logo_link(): void
    {
        $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/profile')
            ->assertOk()
            ->assertJsonPath('facility.logo_url', null);

        $this->actingAs($this->supervisor)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertStringContainsString(
            'signature=',
            (string) $this->actingAs($this->supervisor)->getJson('/api/blood-center/profile')->json('facility.logo_url')
        );
    }

    public function test_the_stock_report_pdf_prints_with_the_logo(): void
    {
        $this->actingAs($this->supervisor)
            ->post('/api/blood-center/facility/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();

        $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/inventory/stock-report')
            ->assertOk()
            ->assertJsonPath('facility.logo_url', fn (?string $url): bool => str_contains((string) $url, 'signature='));

        $this->actingAs($this->supervisor)
            ->get('/api/blood-center/inventory/stock-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
