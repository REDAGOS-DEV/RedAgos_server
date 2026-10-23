<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodRequestStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Service\AuditLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Blood Center Admin's reports for a period.
 */
class AdminReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', config('blood_center.timezone')));
        $this->centre = Facility::factory()->approved()->create();
        $this->admin = User::factory()->bloodCenterAdmin($this->centre)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function report(string $from = '2026-10-01', string $to = '2026-10-20'): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/blood-center/reports/centre?from={$from}&to={$to}")
            ->assertOk()
            ->json();
    }

    public function test_it_counts_the_centres_donations_in_the_period_only(): void
    {
        Donation::factory()->count(2)->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-10-05 09:00', 'status' => DonationStatus::Completed]);
        Donation::factory()->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-09-05 09:00', 'status' => DonationStatus::Completed]);
        Donation::factory()->create(['donation_date' => '2026-10-05 09:00', 'status' => DonationStatus::Completed]);

        $data = $this->report();

        $this->assertSame(2, $data['centre']['donations']);
        $this->assertSame(20, $data['period']['days']);
        $this->assertCount(20, $data['centre']['daily']);
        $this->assertSame(2, collect($data['centre']['daily'])->firstWhere('date', '2026-10-05')['donations']);
    }

    public function test_it_sums_up_the_requests_sent_to_the_centre(): void
    {
        $hospital = Facility::factory()->approved()->create();
        BloodRequest::factory()->create([
            'facility_id' => $hospital->id, 'target_facility_id' => $this->centre->id,
            'request_date' => '2026-10-10 08:00', 'fulfilled_at' => '2026-10-10 11:00',
            'status' => BloodRequestStatus::Fulfilled, 'urgency_level' => UrgencyLevel::Emergency,
        ]);
        $open = BloodRequest::factory()->create([
            'facility_id' => $hospital->id, 'target_facility_id' => $this->centre->id,
            'request_date' => '2026-10-12 08:00', 'status' => BloodRequestStatus::Pending,
        ]);
        RequestAllocation::factory()->create(['request_id' => $open->id, 'released_at' => '2026-10-12 10:00']);

        $requests = $this->report()['centre']['requests'];

        $this->assertSame(2, $requests['received']);
        $this->assertSame(1, $requests['emergency']);
        $this->assertSame(1, $requests['fulfilled']);
        $this->assertSame(1, $requests['open']);
        $this->assertEquals(3.0, $requests['avg_hours_to_fulfil']);
        $this->assertSame(1, $this->report()['centre']['units']['released']);
    }

    public function test_it_counts_expired_units(): void
    {
        BloodUnit::factory()->create(['facility_id' => $this->centre->id, 'expired_at' => '2026-10-15 01:00']);

        $this->assertSame(1, $this->report()['centre']['units']['expired']);
    }

    public function test_it_reports_staff_changes_sign_ins_and_activity(): void
    {
        $logger = app(AuditLogger::class);
        $clerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();
        $logger->record($this->admin, 'staff.created', $clerk, ['facility_id' => $this->centre->id]);
        $logger->record($this->admin, 'staff.role_changed', $clerk, ['facility_id' => $this->centre->id]);
        $logger->record($clerk, 'auth.signed_in', $clerk, ['facility_id' => $this->centre->id]);

        $data = $this->report();

        $this->assertSame(1, $data['staff']['created']);
        $this->assertSame(1, $data['staff']['role_changes']);
        $this->assertSame(1, $data['staff']['sign_ins']);
        $this->assertSame('Changed staff roles', $data['staff']['events'][0]['label']);
        $this->assertSame($clerk->signatureName(), $data['staff']['events'][0]['subject']);
        $this->assertSame(3, $data['activity']['total']);
        $this->assertSame(2, $data['activity']['top_people'][0]['count']);
    }

    public function test_the_period_is_checked(): void
    {
        $this->actingAs($this->admin)->getJson('/api/blood-center/reports/centre?from=2026-10-20&to=2026-10-01')->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/api/blood-center/reports/centre?from=2024-01-01&to=2026-10-01')->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/api/blood-center/reports/centre?from=yesterday&to=2026-10-01')->assertUnprocessable();
    }

    public function test_a_department_role_may_not_read_it(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff($this->centre, StaffRole::Phlebotomist)->create())
            ->getJson('/api/blood-center/reports/centre?from=2026-10-01&to=2026-10-20')
            ->assertForbidden();
    }
}
