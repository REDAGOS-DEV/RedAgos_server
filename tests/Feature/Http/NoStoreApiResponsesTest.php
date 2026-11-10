<?php

namespace Tests\Feature\Http;

use App\Enums\StaffRole;
use App\Http\Middleware\NoStoreApiResponses;
use App\Models\Facility;
use App\Models\User;
use App\Service\FacilityLogoService;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * No API response may be stored by a browser, except the ones named in
 * NoStoreApiResponses::CACHEABLE_ROUTES.
 *
 * Without a directive Symfony sends `no-cache, private`, which still lets a
 * browser keep donor records and bills in its disk cache on a shared computer.
 */
class NoStoreApiResponsesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_json_response_is_not_to_be_stored(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $this->assertNoStore(
            $this->actingAs($staff)->getJson('/api/blood-center/inventory/summary')->assertOk()
        );
    }

    public function test_refusals_are_not_to_be_stored_either(): void
    {
        $this->assertNoStore($this->getJson('/api/blood-center/inventory/summary')->assertUnauthorized());

        $staff = User::factory()->bloodCenterStaff()->create();

        $this->assertNoStore(
            $this->actingAs($staff)->getJson('/api/blood-center/billing-transactions')->assertForbidden()
        );
    }

    public function test_the_billing_transactions_export_is_not_to_be_stored(): void
    {
        $clerk = User::factory()->bloodCenterStaff(null, StaffRole::BillingClerk)->create();

        $this->assertNoStore(
            $this->actingAs($clerk)->get('/api/blood-center/billing-transactions/export')->assertOk()
        );
    }

    public function test_a_max_age_set_by_a_route_not_on_the_list_is_overwritten(): void
    {
        Route::middleware('api')->get('/api/testing/cache-header', fn () => response('ok')
            ->header('Cache-Control', 'public, max-age=600'));

        $response = $this->get('/api/testing/cache-header')->assertOk();

        $this->assertNoStore($response);
        $this->assertStringNotContainsString('max-age', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_facility_logo_keeps_its_own_caching(): void
    {
        $this->assertContains('blood-center.facility.logo', NoStoreApiResponses::CACHEABLE_ROUTES);

        Storage::fake(Uploads::diskName());

        $facility = Facility::factory()->approved()->create();
        $path = UploadedFile::fake()->image('logo.png', 40, 40)->store('facility-logos', Uploads::diskName());
        $facility->forceFill(['logo_path' => $path])->save();

        $url = app(FacilityLogoService::class)->urlFor($facility->fresh());

        $cacheControl = (string) $this->get($url)->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('max-age=1800', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
    }

    private function assertNoStore(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
