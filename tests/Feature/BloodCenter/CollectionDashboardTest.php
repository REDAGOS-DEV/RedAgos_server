<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\MobileEvent;
use App\Models\User;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Donor/Collection dashboard.
 *
 * What is locked in: each section counts this centre only, "today" is the
 * operational day, a visit counts once at the furthest point it reached, the
 * pool reads eligibility exactly as the Donors list does, and no donor is
 * ever named — the collection chair reads this page too.
 */
class CollectionDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    /**
     * A moment on an operational day, as the database stores it.
     */
    private function at(int $daysFromToday, int $hour = 10): CarbonImmutable
    {
        return OperationalDay::today()->startOfDay()->addDays($daysFromToday)->setTime($hour, 0)
            ->setTimezone((string) config('app.timezone'));
    }

    private function visit(int $daysFromToday, DonationStatus $status, ?User $donor = null, ?Facility $at = null): Donation
    {
        $donor ??= User::factory()->donor()->create();

        return Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'donation_date' => $this->at($daysFromToday),
            'status' => $status,
        ]);
    }

    private ?User $booker = null;

    /**
     * One donor holds every appointment: the factory gives each donor a
     * unique blood type, and there are only eight.
     */
    private function appointment(int $daysFromToday, AppointmentStatus $status, array $extra = []): DonationAppointment
    {
        $this->booker ??= User::factory()->donor()->create();

        return DonationAppointment::factory()->create([
            'donor_id' => $this->booker->id,
            'facility_id' => $this->facility->id,
            'appointment_datetime' => $this->at($daysFromToday),
            'status' => $status,
            ...$extra,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        return $this->actingAs($this->staff)
            ->getJson('/api/blood-center/collection/dashboard')
            ->assertOk()
            ->json();
    }

    public function test_today_counts_appointments_and_each_visit_once_at_its_furthest_stage(): void
    {
        $this->appointment(0, AppointmentStatus::Scheduled);
        $this->appointment(0, AppointmentStatus::Confirmed);
        $this->appointment(0, AppointmentStatus::NoShow);
        $this->appointment(1, AppointmentStatus::Scheduled);

        $this->visit(0, DonationStatus::Registered);
        $this->visit(0, DonationStatus::Collected);
        $deferred = $this->visit(0, DonationStatus::Rejected);
        DonationScreening::factory()->deferred()->create(['donation_id' => $deferred->id, 'facility_id' => $this->facility->id]);
        $this->visit(-1, DonationStatus::Collected);
        $this->visit(0, DonationStatus::Collected, at: Facility::factory()->approved()->create());

        $today = $this->dashboard()['today'];

        $this->assertSame(['total' => 3, 'waiting' => 1, 'in_progress' => 1, 'collected' => 0, 'no_show' => 1, 'cancelled' => 0], $today['appointments']);
        $this->assertSame(3, $today['donations']['total']);
        $this->assertSame(1, $today['donations']['registered']);
        $this->assertSame(1, $today['donations']['collected']);
        $this->assertSame(1, $today['donations']['deferred']);
    }

    public function test_the_pool_reads_eligibility_as_the_donors_list_does(): void
    {
        $eligible = $this->visit(-200, DonationStatus::Completed)->donorProfile->donor;
        $this->visit(-3, DonationStatus::Collected);

        $deferred = $this->visit(-300, DonationStatus::Completed);
        DonationScreening::factory()->permanentlyDeferred()->create(['donation_id' => $deferred->id, 'facility_id' => $this->facility->id]);

        $inactive = $this->visit(-200, DonationStatus::Completed)->donorProfile->donor;
        FacilityDonor::create(['facility_id' => $this->facility->id, 'donor_id' => $inactive->id, 'status' => 'inactive']);

        $pool = $this->dashboard()['pool'];

        $this->assertSame(4, $pool['total']);
        $this->assertSame(1, $pool['eligible']);
        $this->assertSame(1, $pool['waiting']);
        $this->assertSame(1, $pool['deferred']);
        $this->assertSame(1, $pool['inactive']);
        $this->assertSame(
            [['code' => $eligible->donorProfile->bloodType->code, 'count' => 1]],
            $pool['eligible_by_blood_type']
        );
    }

    public function test_coming_up_is_the_next_seven_days_with_this_centres_drives(): void
    {
        $this->appointment(1, AppointmentStatus::Scheduled);
        $this->appointment(1, AppointmentStatus::Cancelled);
        $this->appointment(7, AppointmentStatus::Scheduled);
        $this->appointment(8, AppointmentStatus::Scheduled);

        $drive = MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => OperationalDay::today()->addDays(3)->toDateString(),
        ]);
        $this->appointment(3, AppointmentStatus::Scheduled, ['event_id' => $drive->id]);
        MobileEvent::factory()->create(['event_date' => OperationalDay::today()->addDays(3)->toDateString()]);
        MobileEvent::factory()->create(['facility_id' => $this->facility->id, 'event_date' => OperationalDay::today()->addDays(9)->toDateString()]);

        $upcoming = $this->dashboard()['upcoming'];
        $days = collect($upcoming['days'])->pluck('appointments', 'date');

        $this->assertCount(7, $upcoming['days']);
        $this->assertSame(OperationalDay::today()->addDay()->toDateString(), $upcoming['days'][0]['date']);
        $this->assertSame(1, $days[OperationalDay::today()->addDay()->toDateString()]);
        $this->assertSame(1, $days[OperationalDay::today()->addDays(3)->toDateString()]);
        $this->assertSame(1, $days[OperationalDay::today()->addDays(7)->toDateString()]);

        $this->assertCount(1, $upcoming['drives']);
        $this->assertSame($drive->id, $upcoming['drives'][0]['id']);
        $this->assertSame(1, $upcoming['drives'][0]['registered']);
    }

    public function test_the_trend_is_thirty_days_of_donations_and_deferrals_ending_today(): void
    {
        $this->visit(-5, DonationStatus::Completed);
        $this->visit(-5, DonationStatus::Collected);
        $deferred = $this->visit(-5, DonationStatus::Rejected);
        DonationScreening::factory()->deferred()->create(['donation_id' => $deferred->id, 'facility_id' => $this->facility->id]);
        $this->visit(-40, DonationStatus::Completed);

        $trend = collect($this->dashboard()['trend'])->keyBy('date');

        $this->assertCount(30, $trend);
        $this->assertSame(OperationalDay::todayAsDate(), $trend->keys()->last());

        $day = $trend[OperationalDay::today()->subDays(5)->toDateString()];
        $this->assertSame(2, $day['donations']);
        $this->assertSame(1, $day['deferrals']);
        $this->assertSame(3, $trend->sum('donations') + $trend->sum('deferrals'));
    }

    public function test_it_names_no_donor(): void
    {
        $donor = $this->visit(0, DonationStatus::Collected)->donorProfile->donor;
        $donor->forceFill(['first_name' => 'Zebulon', 'last_name' => 'Quixote'])->save();

        $body = $this->actingAs($this->staff)->getJson('/api/blood-center/collection/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('Zebulon', $body);
        $this->assertStringNotContainsString('Quixote', $body);
        $this->assertStringNotContainsString($donor->uuid, $body);
    }

    public function test_every_role_at_the_counter_reads_it_and_no_one_else(): void
    {
        foreach ([StaffRole::ScreeningPhysician, StaffRole::Phlebotomist, StaffRole::MedicalReceptionist] as $role) {
            $this->actingAs(User::factory()->bloodCenterStaff($this->facility, $role)->create())
                ->getJson('/api/blood-center/collection/dashboard')
                ->assertOk();
        }

        $this->actingAs(User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create())
            ->getJson('/api/blood-center/collection/dashboard')
            ->assertForbidden();
    }
}
