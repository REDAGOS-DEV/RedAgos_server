<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\StaffRole;
use App\Models\BloodUnit;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\DeferralsEnding;
use App\Notifications\DriveTomorrow;
use App\Notifications\MorningBriefing;
use App\Notifications\ReferralsWaiting;
use App\Notifications\UnitsExpiringSoon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The blood centres' morning reminders (blood-center:send-reminders), each to
 * whoever handles it.
 */
class StaffRemindersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private User $receptionist;

    private User $officer;

    private User $labHead;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-22 07:00', config('blood_center.timezone')));

        $this->centre = Facility::factory()->approved()->create();
        $this->receptionist = User::factory()->bloodCenterStaff($this->centre, StaffRole::MedicalReceptionist)->create();
        $this->officer = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();
        $this->labHead = User::factory()->bloodCenterStaff($this->centre, StaffRole::LabSupervisor)->create();
        $this->clerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_tomorrows_drive_also_reaches_the_physician_who_screens_there(): void
    {
        MobileEvent::factory()->create(['facility_id' => $this->centre->id, 'event_date' => '2026-10-23']);
        $physician = User::factory()->bloodCenterStaff($this->centre, StaffRole::ScreeningPhysician)->create();

        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertSentTo($physician, DriveTomorrow::class, fn (DriveTomorrow $n): bool => $n->toDatabase($physician)['action_route'] === '/blood-center/appointments');
        Notification::assertSentTo($this->receptionist, DriveTomorrow::class, fn (DriveTomorrow $n): bool => $n->toDatabase($this->receptionist)['action_route'] === '/blood-center/drives');
    }

    public function test_the_morning_briefing_reaches_the_appointment_roles_on_a_booked_day(): void
    {
        DonationAppointment::factory()->count(2)->create(['facility_id' => $this->centre->id, 'appointment_datetime' => '2026-10-22 09:00']);
        $physician = User::factory()->bloodCenterStaff($this->centre, StaffRole::ScreeningPhysician)->create();
        $physician->forceFill(['notification_preferences' => ['morning_briefing' => false]])->save();

        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->receptionist, MorningBriefing::class, fn (MorningBriefing $n): bool => str_starts_with($n->toDatabase($this->receptionist)['title'], 'Today: 2 appointments'));
        Notification::assertNotSentTo([$physician, $this->officer, $this->clerk], MorningBriefing::class);
    }

    public function test_no_briefing_on_a_day_with_nothing_booked(): void
    {
        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($this->receptionist);
    }

    public function test_deferrals_ending_today_reach_the_physician_once(): void
    {
        $physician = User::factory()->bloodCenterStaff($this->centre, StaffRole::ScreeningPhysician)->create();
        DonorDeferral::factory()->create(['facility_id' => $this->centre->id, 'ends_on' => '2026-10-22']);
        DonorDeferral::factory()->create(['facility_id' => $this->centre->id, 'ends_on' => '2026-10-23']);
        DonorDeferral::factory()->create(['facility_id' => $this->centre->id, 'ends_on' => '2026-10-22', 'lifted_at' => '2026-10-20 10:00']);

        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertSentTo($physician, DeferralsEnding::class, fn (DeferralsEnding $n): bool => $n->toDatabase($physician)['title'] === 'A donor\'s deferral ends today');
        Notification::assertNotSentTo([$this->receptionist, $this->officer], DeferralsEnding::class);
    }

    public function test_tomorrows_drive_reaches_whoever_runs_drives(): void
    {
        MobileEvent::factory()->create(['facility_id' => $this->centre->id, 'event_date' => '2026-10-23']);
        MobileEvent::factory()->create(['facility_id' => $this->centre->id, 'event_date' => '2026-10-25']);

        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->receptionist, DriveTomorrow::class, 1);
        Notification::assertNotSentTo([$this->officer, $this->clerk], DriveTomorrow::class);
    }

    public function test_units_close_to_expiry_reach_inventory_viewers_who_want_it(): void
    {
        BloodUnit::factory()->create(['facility_id' => $this->centre->id, 'expiry_date' => '2026-10-24']);
        BloodUnit::factory()->create(['facility_id' => $this->centre->id, 'expiry_date' => '2026-12-01']);
        BloodUnit::factory()->create(['facility_id' => $this->centre->id, 'expiry_date' => '2026-10-23', 'status' => BloodUnitStatus::Issued]);

        $dispatcher = User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create();
        $dispatcher->forceFill(['notification_preferences' => ['expiring_units' => false]])->save();

        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->officer, UnitsExpiringSoon::class, function (UnitsExpiringSoon $notice): bool {
            return $notice->toDatabase($this->officer)['title'] === '1 unit expiring within 3 days';
        });
        Notification::assertNotSentTo([$dispatcher, $this->receptionist], UnitsExpiringSoon::class);
    }

    public function test_a_referral_left_pending_is_chased_once_on_the_day_it_turns_three_days_old(): void
    {
        $due = Donation::factory()->create(['facility_id' => $this->centre->id]);
        $fresh = Donation::factory()->create(['facility_id' => $this->centre->id]);

        $old = CounsellingReferral::create(['donation_id' => $due->id, 'donor_id' => $due->donor_id, 'facility_id' => $this->centre->id]);
        $old->forceFill(['created_at' => Carbon::parse('2026-10-19 10:00')])->save();
        CounsellingReferral::create(['donation_id' => $fresh->id, 'donor_id' => $fresh->donor_id, 'facility_id' => $this->centre->id]);

        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->labHead, ReferralsWaiting::class, 1);
        Notification::assertNotSentTo([$this->officer, $this->clerk], ReferralsWaiting::class);
    }

    public function test_a_quiet_day_sends_nothing(): void
    {
        $this->artisan('blood-center:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
