<?php

namespace Tests\Feature\Admin;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The network report (2026-10-15): stock as hospitals would see it, request
 * counts in the range, CSV exports, and only for admins who may export.
 */
class NetworkReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->withRole(RoleName::Admin)->create();
    }

    public function test_stock_counts_only_issuable_units_at_centers_in_service(): void
    {
        // As the billing fixtures do: one blood type, and a donor of that type,
        // so the nested factories do not mint a second O+.
        $oPos = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $donor = DonorProfile::factory()->create(['donor_id' => User::factory()->create()->id, 'blood_type_id' => $oPos->id]);
        $open = Facility::factory()->approved()->create(['name' => 'Open Center']);
        $closed = Facility::factory()->approved()->create(['name' => 'Closed Center']);

        $units = function (Facility $facility, int $count = 1, array $extra = []) use ($oPos, $donor): void {
            $donation = Donation::factory()->create(['facility_id' => $facility->id, 'donor_id' => $donor->donor_id]);
            BloodUnit::factory()->count($count)->create([
                'facility_id' => $facility->id,
                'blood_type_id' => $oPos->id,
                'donation_id' => $donation->id,
                ...$extra,
            ]);
        };

        $units($open, 3);
        // Not counted: reserved, past its date, or at a facility out of service.
        $units($open, 1, ['status' => BloodUnitStatus::Reserved]);
        $units($open, 1, ['expiry_date' => OperationalDay::today()->subDay()->toDateString()]);
        $units($closed);
        $closed->forceFill(['status' => FacilityStatus::Inactive])->save();

        $response = $this->actingAs($this->admin())->getJson('/api/admin/reports/summary')->assertOk();

        $this->assertSame(3, $response->json('stock.total'));
        $this->assertSame(3, collect($response->json('stock.by_type'))->firstWhere('blood_type', 'O+')['available']);
        $this->assertSame(['Open Center'], collect($response->json('stock.by_center'))->pluck('name')->all());
    }

    public function test_requests_are_counted_by_status_within_the_range(): void
    {
        $in = OperationalDay::today()->subDays(2);
        BloodRequest::factory()->create(['status' => BloodRequestStatus::Fulfilled, 'request_date' => $in]);
        BloodRequest::factory()->create(['status' => BloodRequestStatus::Pending, 'request_date' => $in, 'urgency_level' => UrgencyLevel::Emergency]);
        BloodRequest::factory()->create(['status' => BloodRequestStatus::Fulfilled, 'request_date' => OperationalDay::today()->subDays(90)]);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/reports/summary')
            ->assertOk()
            ->assertJsonPath('requests.total', 2)
            ->assertJsonPath('requests.fulfilled', 1)
            ->assertJsonPath('requests.open', 1)
            ->assertJsonPath('requests.emergency', 1);
    }

    public function test_the_range_defaults_to_the_last_thirty_days_and_can_be_chosen(): void
    {
        $admin = $this->admin();
        $today = OperationalDay::today();

        $this->actingAs($admin)->getJson('/api/admin/reports/summary')
            ->assertJsonPath('range.to', $today->toDateString())
            ->assertJsonPath('range.from', $today->subDays(29)->toDateString());

        $this->actingAs($admin)->getJson('/api/admin/reports/summary?from=2026-01-01&to=2026-01-31')
            ->assertJsonPath('range.from', '2026-01-01');

        $this->actingAs($admin)->getJson('/api/admin/reports/summary?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable();
    }

    public function test_each_dataset_exports_as_csv(): void
    {
        $admin = $this->admin();

        foreach (['stock' => 'Blood center', 'donations' => 'Blood center', 'requests' => 'Requesting facility'] as $dataset => $firstColumn) {
            $response = $this->actingAs($admin)->get("/api/admin/reports/export?dataset={$dataset}");

            $response->assertOk();
            $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
            $csv = $response->streamedContent();
            // A UTF-8 byte order mark first, so Excel reads accented names right.
            $this->assertStringStartsWith("\u{FEFF}", $csv);
            $this->assertStringContainsString('"'.$firstColumn.'"', strtok($csv, "\n"));
        }

        $this->actingAs($admin)->getJson('/api/admin/reports/export?dataset=patients')->assertUnprocessable();
    }

    public function test_only_admins_who_may_export_can_read_it(): void
    {
        $this->getJson('/api/admin/reports/summary')->assertUnauthorized();

        $this->actingAs(User::factory()->bloodCenterSupervisor()->create())
            ->getJson('/api/admin/reports/summary')
            ->assertForbidden();
    }
}
