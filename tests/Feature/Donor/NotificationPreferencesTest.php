<?php

namespace Tests\Feature\Donor;

use App\Enums\StaffRole;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\BloodDriveScheduled;
use App\Support\DonorNotificationPreferences;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The switches on the donor's Settings screen, and that the notices obey them.
 */
class NotificationPreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function donor(?array $preferences = null): User
    {
        $donor = User::factory()->donor()->create();

        if ($preferences !== null) {
            DonorProfile::where('donor_id', $donor->id)->update(['notification_preferences' => $preferences]);
        }

        return $donor->fresh(['donorProfile']);
    }

    public function test_the_profile_lists_every_switch_with_its_default(): void
    {
        $this->actingAs($this->donor())
            ->getJson('/api/donors/profile')
            ->assertOk()
            ->assertJsonPath('notification_preferences', DonorNotificationPreferences::DEFAULTS);
    }

    public function test_a_choice_saved_by_the_old_screen_carries_over(): void
    {
        $donor = $this->donor(['appointment_reminders' => false, 'nearby_drives' => true, 'email_updates' => true]);

        $this->actingAs($donor)
            ->getJson('/api/donors/profile')
            ->assertOk()
            ->assertJsonPath('notification_preferences.appointments_email', false)
            ->assertJsonPath('notification_preferences.drives_email', true)
            ->assertJsonMissingPath('notification_preferences.email_updates');
    }

    public function test_saving_one_switch_keeps_the_others_and_drops_old_keys(): void
    {
        $donor = $this->donor(['appointment_reminders' => false, 'email_updates' => true]);

        $this->actingAs($donor)
            ->patchJson('/api/donors/notification-preferences', ['drives_email' => true, 'unknown' => true])
            ->assertOk()
            ->assertJsonPath('notification_preferences.drives_email', true)
            ->assertJsonPath('notification_preferences.appointments_email', false);

        $stored = DonorProfile::where('donor_id', $donor->id)->value('notification_preferences');
        $this->assertSame(array_keys(DonorNotificationPreferences::DEFAULTS), array_keys($stored));
    }

    public function test_a_switch_must_be_a_boolean(): void
    {
        $this->actingAs($this->donor())
            ->patchJson('/api/donors/notification-preferences', ['results_email' => 'sometimes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('results_email');
    }

    public function test_email_off_keeps_the_in_app_copy_of_appointments_and_results(): void
    {
        $donor = $this->donor(['appointments_email' => false, 'results_email' => false]);

        $this->assertSame(['database'], DonorNotificationPreferences::alwaysInAppChannels($donor, DonorNotificationPreferences::APPOINTMENTS_EMAIL));
        $this->assertSame(['database'], DonorNotificationPreferences::alwaysInAppChannels($donor, DonorNotificationPreferences::RESULTS_EMAIL));
        $this->assertSame(['mail', 'database'], DonorNotificationPreferences::alwaysInAppChannels($this->donor(), DonorNotificationPreferences::RESULTS_EMAIL));
    }

    public function test_drive_notices_follow_both_switches(): void
    {
        $channels = fn (User $donor): array => DonorNotificationPreferences::optionalChannels(
            $donor,
            DonorNotificationPreferences::DRIVES_IN_APP,
            DonorNotificationPreferences::DRIVES_EMAIL,
        );

        $this->assertSame(['database'], $channels($this->donor()));
        $this->assertSame(['mail', 'database'], $channels($this->donor(['drives_email' => true])));
        $this->assertSame([], $channels($this->donor(['drives_in_app' => false])));
    }

    public function test_scheduling_a_drive_tells_the_centres_active_donors_only(): void
    {
        Notification::fake();
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility, StaffRole::MedicalReceptionist)->create();

        $ours = $this->donor();
        $inactive = $this->donor();
        $elsewhere = $this->donor();
        FacilityDonor::create(['facility_id' => $facility->id, 'donor_id' => $ours->id, 'registered_here' => true]);
        FacilityDonor::create(['facility_id' => $facility->id, 'donor_id' => $inactive->id, 'status' => 'inactive']);
        FacilityDonor::create(['facility_id' => Facility::factory()->approved()->create()->id, 'donor_id' => $elsewhere->id]);

        $this->actingAs($staff)
            ->postJson('/api/blood-center/drives', [
                'name' => 'UM Matina Bloodletting Drive',
                'location' => 'University of Mindanao, Matina, Davao City',
                'event_date' => now()->addWeek()->toDateString(),
                'max_capacity' => 60,
                'start_time' => '08:00',
                'end_time' => '16:00',
            ])
            ->assertCreated();

        Notification::assertSentTo($ours, BloodDriveScheduled::class, function (BloodDriveScheduled $notice, array $channels) use ($ours): bool {
            $data = $notice->toDatabase($ours);

            return $channels === ['database']
                && $data['action_route'] === '/donor/appointments'
                && str_contains($data['desc'], '8:00 AM to 4:00 PM');
        });
        Notification::assertNotSentTo([$inactive, $elsewhere], BloodDriveScheduled::class);
        $this->assertSame(1, MobileEvent::count());
    }
}
