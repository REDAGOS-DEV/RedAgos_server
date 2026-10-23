<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationScreening;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Donor & Collection department's reports for a period.
 */
class CollectionReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', config('blood_center.timezone')));
        $this->centre = Facility::factory()->approved()->create();
        $this->physician = User::factory()->bloodCenterStaff($this->centre, StaffRole::ScreeningPhysician)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function report(): array
    {
        return $this->actingAs($this->physician)
            ->getJson('/api/blood-center/reports/collection?from=2026-10-01&to=2026-10-20')
            ->assertOk()
            ->json();
    }

    public function test_it_counts_donations_and_deferrals_with_their_reasons(): void
    {
        Donation::factory()->count(3)->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-10-05 09:00', 'status' => DonationStatus::Completed]);
        $deferred = Donation::factory()->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-10-06 09:00', 'status' => DonationStatus::Rejected]);
        DonationScreening::factory()->create([
            'donation_id' => $deferred->id,
            'facility_id' => $this->centre->id,
            'outcome' => ScreeningOutcome::TemporarilyDeferred,
            'deferral_reason' => 'Low hemoglobin',
        ]);

        $data = $this->report();

        $this->assertSame(3, $data['donations']['collected']);
        $this->assertSame(1, $data['donations']['deferred']);
        $this->assertEquals(25.0, $data['donations']['deferral_rate']);
        $this->assertSame('Low hemoglobin', $data['deferrals']['top_reasons'][0]['reason']);
        $this->assertSame('Temporarily Deferred', $data['deferrals']['by_type'][0]['type']);
        $this->assertSame(3, collect($data['daily'])->firstWhere('date', '2026-10-05')['donations']);
    }

    public function test_it_counts_the_appointments_and_the_show_rate(): void
    {
        DonationAppointment::factory()->create(['facility_id' => $this->centre->id, 'appointment_datetime' => '2026-10-08 09:00', 'status' => AppointmentStatus::Completed]);
        DonationAppointment::factory()->create(['facility_id' => $this->centre->id, 'appointment_datetime' => '2026-10-08 10:00', 'status' => AppointmentStatus::NoShow]);
        DonationAppointment::factory()->create(['facility_id' => $this->centre->id, 'appointment_datetime' => '2026-09-08 10:00', 'status' => AppointmentStatus::Completed]);

        $appointments = $this->report()['appointments'];

        $this->assertSame(2, $appointments['booked']);
        $this->assertSame(1, $appointments['no_show']);
        $this->assertEquals(50.0, $appointments['show_rate']);
    }

    public function test_the_donor_profile_counts_sex_age_and_type_of_donor(): void
    {
        $given = ['status' => DonationStatus::Completed];
        $elsewhere = Facility::factory()->approved()->create();

        // First time: a woman of 20, never donated.
        $first = DonorProfile::factory()->create(['gender' => 'female', 'birth_date' => '2006-01-15']);
        Donation::factory()->create($given + ['donor_id' => $first->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-05 09:00']);

        // New to the centre: a man of 30 who gave at another centre.
        $newHere = DonorProfile::factory()->create(['gender' => 'male', 'birth_date' => '1996-03-01']);
        Donation::factory()->create($given + ['donor_id' => $newHere->donor_id, 'facility_id' => $elsewhere->id, 'donation_date' => '2026-05-01 09:00']);
        Donation::factory()->create($given + ['donor_id' => $newHere->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-06 09:00']);

        // Repeat: gave here four months before.
        $repeat = DonorProfile::factory()->create(['gender' => 'male', 'birth_date' => '1976-03-01']);
        Donation::factory()->create($given + ['donor_id' => $repeat->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-06-01 09:00']);
        Donation::factory()->create($given + ['donor_id' => $repeat->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-07 09:00']);

        // Lapsed: last gave here two years before.
        $lapsed = DonorProfile::factory()->create(['gender' => 'female', 'birth_date' => '2009-01-01']);
        Donation::factory()->create($given + ['donor_id' => $lapsed->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2024-09-01 09:00']);
        Donation::factory()->create($given + ['donor_id' => $lapsed->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-08 09:00']);

        $profile = $this->report()['donor_profile'];
        $total = $profile['total'];

        $this->assertSame(4, $total['total']);
        $this->assertSame(['female' => 2, 'male' => 2, 'not_stated' => 0], $total['sex']);
        $this->assertSame(1, $total['age']['16-17']);
        $this->assertSame(1, $total['age']['18-24']);
        $this->assertSame(1, $total['age']['25-44']);
        $this->assertSame(1, $total['age']['45-64']);
        $this->assertSame(['new_to_centre' => 1, 'first_time' => 1, 'repeat_retained' => 1, 'lapsed' => 1], $total['type']);
        $this->assertSame(4, $total['arrival']['walk_in']);
        $this->assertSame('2026-10', $profile['months'][0]['month']);
    }

    public function test_it_compares_with_the_period_before_and_maps_the_busiest_hours(): void
    {
        $given = ['facility_id' => $this->centre->id, 'status' => DonationStatus::Completed];
        // Monday 5 October 2026, 9:15 in Manila (01:15 UTC), twice; and one in the period before.
        Donation::factory()->count(2)->create($given + ['donation_date' => '2026-10-05 01:15']);
        Donation::factory()->create($given + ['donation_date' => '2026-09-20 10:00']);

        $data = $this->report();

        $this->assertSame('2026-09-11', $data['previous']['from']);
        $this->assertSame('2026-09-30', $data['previous']['to']);
        $this->assertSame(1, $data['previous']['collected']);
        $this->assertSame(2, $data['busiest'][0][9]);
    }

    public function test_someone_outside_collection_may_not_read_it(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create())
            ->getJson('/api/blood-center/reports/collection?from=2026-10-01&to=2026-10-20')
            ->assertForbidden();
    }
}
