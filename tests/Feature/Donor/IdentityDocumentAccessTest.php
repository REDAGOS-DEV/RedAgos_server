<?php

namespace Tests\Feature\Donor;

use App\Enums\RoleName;
use App\Enums\ValidIdType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Who may open a donor's identity document.
 *
 * A government ID deliberately does not share the avatar's exposure model: the
 * avatar route is signed and opens for anyone holding the link, which would make
 * an ID forwardable and would leave no authenticated viewer to record.
 */
class IdentityDocumentAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->donor = User::factory()->donor()->create();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/identity', [
                'valid_id_type' => ValidIdType::DriversLicense->value,
                'valid_id_number' => 'N0123456789',
                'valid_id_image' => UploadedFile::fake()->create('id.jpg', 240, 'image/jpeg'),
            ])
            ->assertOk();

        $this->url = "/api/donors/{$this->donor->uuid}/identity-image";

        // actingAs() above persists for the rest of the test. Cleared so the
        // unauthenticated cases are genuinely unauthenticated rather than
        // quietly inheriting the donor's session.
        $this->app['auth']->forgetGuards();
    }

    public function test_a_donor_may_open_their_own_document(): void
    {
        $this->actingAs($this->donor)->get($this->url)->assertOk();
    }

    public function test_a_donor_may_not_open_another_donors_document(): void
    {
        $other = User::factory()->donor()->create();

        $this->actingAs($other)->get($this->url)->assertForbidden();
    }

    public function test_an_administrator_may_open_the_document_and_the_view_is_audited(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->get($this->url)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'donor.identity_image_viewed',
        ]);
    }

    public function test_the_donors_own_view_is_not_audited(): void
    {
        $this->actingAs($this->donor)->get($this->url)->assertOk();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'donor.identity_image_viewed',
        ]);
    }

    public function test_blood_center_staff_may_not_open_the_document(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff()->create())
            ->get($this->url)
            ->assertForbidden();
    }

    public function test_a_blood_center_supervisor_may_not_open_the_document(): void
    {
        // A supervisor holds every department ability. None of them is this one,
        // which is why the abilities are registered as explicit gates rather
        // than through Gate::before().
        $this->actingAs(User::factory()->bloodCenterSupervisor()->create())
            ->get($this->url)
            ->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson($this->url)->assertUnauthorized();
    }

    public function test_a_signed_link_is_not_a_way_in(): void
    {
        // The signed-URL shape that opens the avatar must not open an ID.
        $signed = URL::temporarySignedRoute(
            'donors.identity-image.show',
            now()->addMinutes(30),
            ['uuid' => $this->donor->uuid]
        );

        $this->getJson($signed)->assertUnauthorized();
    }

    public function test_the_document_is_served_uncacheable_and_unsniffable(): void
    {
        $response = $this->actingAs($this->donor)->get($this->url)->assertOk();

        // no-store keeps the document out of browser and proxy caches, where it
        // would outlive the session that was allowed to see it. Asserted by
        // directive rather than as one string: Symfony reorders and normalises
        // the header it emits.
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_donor_with_no_document_returns_not_found(): void
    {
        $other = User::factory()->donor()->create();

        $this->actingAs($other)
            ->get("/api/donors/{$other->uuid}/identity-image")
            ->assertNotFound();
    }

    public function test_no_donor_facing_endpoint_exposes_the_storage_path(): void
    {
        $path = $this->donor->donorProfile->fresh()->valid_id_image_path;

        foreach (['/api/donors/profile', '/api/donors/dashboard', '/api/user'] as $uri) {
            $body = $this->actingAs($this->donor)->getJson($uri)->assertOk()->getContent();

            $this->assertStringNotContainsString('valid_id_image_path', $body, $uri);
            $this->assertStringNotContainsString($path, $body, $uri);
        }
    }
}
