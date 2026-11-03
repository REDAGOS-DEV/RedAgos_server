<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\BloodDriveScheduled;
use App\Notifications\DriveCancelled;
use App\Service\BloodDriveAnnouncer;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A drive's lifecycle (drive pre-registration, phase 1): expected participants
 * beside the hard capacity, drafts and publishing, closing registration,
 * editing, and cancelling with the donors told.
 */
class DriveLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create([
            'is_accepting_donations' => true,
            'slot_interval_minutes' => 60,
        ]);
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'UM Matina Bloodletting Drive',
            'location' => 'University of Mindanao, Matina, Davao City',
            'event_date' => now()->addWeek()->toDateString(),
            'max_capacity' => 60,
            'expected_participants' => 120,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'assigned_staff' => null,
            'announcement' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function drive(array $attributes = []): MobileEvent
    {
        return MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => now()->addWeek()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '12:00',
            ...$attributes,
        ]);
    }

    private function book(MobileEvent $drive, AppointmentStatus $status = AppointmentStatus::Scheduled): DonationAppointment
    {
        return DonationAppointment::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => $drive->facility_id,
            'event_id' => $drive->id,
            'appointment_datetime' => $drive->event_date->copy()->setTime(9, 0),
            'status' => $status,
        ]);
    }

    private function donorBooks(MobileEvent $drive): TestResponse
    {
        return $this->actingAs(User::factory()->donor()->create())
            ->postJson('/api/donors/appointments', [
                'type' => 'mobile',
                'drive_id' => $drive->id,
                'time_slot' => '09:00',
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function catalogueIds(): array
    {
        return collect($this->actingAs(User::factory()->donor()->create())->getJson('/api/blood-drives')->assertOk()->json())
            ->pluck('id')
            ->all();
    }

    // --- Expected participants and capacity ----------------------------------

    public function test_expected_participants_is_recorded_apart_from_the_capacity(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertCreated()
            ->assertJsonPath('capacity', 60)
            ->assertJsonPath('expected_participants', 120);

        $this->assertDatabaseHas('mobile_events', ['max_capacity' => 60, 'expected_participants' => 120]);
    }

    public function test_the_capacity_is_optional_and_empty_means_no_limit(): void
    {
        $id = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['max_capacity' => null, 'expected_participants' => null]))
            ->assertCreated()
            ->assertJsonPath('capacity', null)
            ->json('id');

        $drive = MobileEvent::query()->findOrFail($id);
        $this->book($drive);
        $this->book($drive);

        $this->donorBooks($drive)->assertCreated();
    }

    public function test_the_capacity_still_refuses_a_booking_once_the_drive_is_full(): void
    {
        $drive = $this->drive(['max_capacity' => 1, 'expected_participants' => 500]);
        $this->book($drive);

        // Expected participants is an estimate; it lends the drive no places.
        $this->donorBooks($drive)->assertStatus(409)->assertJsonPath('code', 'drive_full');
    }

    // --- Drafts and publishing -----------------------------------------------

    public function test_a_draft_is_hidden_from_donors_and_announced_to_nobody(): void
    {
        $this->mock(BloodDriveAnnouncer::class, fn (MockInterface $mock) => $mock->shouldReceive('announce')->never());

        $id = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['publish' => false]))
            ->assertCreated()
            ->assertJsonPath('status', 'Draft')
            ->assertJsonPath('published_at', null)
            ->assertJsonPath('registration_open', false)
            ->json('id');

        $this->assertNotContains($id, $this->catalogueIds());
        $this->donorBooks(MobileEvent::query()->findOrFail($id))->assertUnprocessable()->assertJsonValidationErrors('drive_id');
    }

    public function test_a_drive_is_published_on_creation_unless_saved_as_a_draft(): void
    {
        $this->mock(BloodDriveAnnouncer::class, fn (MockInterface $mock) => $mock->shouldReceive('announce')->once());

        $id = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'Upcoming')
            ->assertJsonPath('registration_open', true)
            ->json('id');

        $this->assertContains($id, $this->catalogueIds());
    }

    public function test_publishing_a_draft_shows_it_to_donors_and_announces_it_once(): void
    {
        $this->mock(BloodDriveAnnouncer::class, fn (MockInterface $mock) => $mock->shouldReceive('announce')->once());

        $drive = $this->drive(['published_at' => null]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'Upcoming')
            ->assertJsonPath('registration_open', true);

        // A second press changes nothing and tells nobody again.
        $this->actingAs($this->staff)->postJson("/api/blood-center/drives/{$drive->id}/publish")->assertOk();

        $this->assertContains($drive->id, $this->catalogueIds());
        $this->donorBooks($drive)->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'drive.published', 'auditable_id' => $drive->id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'drive.published')->count());
    }

    public function test_publishing_reaches_the_centres_donors(): void
    {
        $donor = User::factory()->donor()->create();
        FacilityDonor::create(['facility_id' => $this->facility->id, 'donor_id' => $donor->id, 'registered_here' => true]);

        $drive = $this->drive(['published_at' => null]);

        $this->actingAs($this->staff)->postJson("/api/blood-center/drives/{$drive->id}/publish")->assertOk();

        Notification::assertSentTo($donor, BloodDriveScheduled::class);
    }

    // --- Registration open and closed -----------------------------------------

    public function test_closing_registration_stops_new_bookings_and_keeps_the_ones_held(): void
    {
        $drive = $this->drive();
        $held = $this->book($drive);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/registration/close")
            ->assertOk()
            ->assertJsonPath('registration_open', false)
            ->assertJsonPath('status', 'Upcoming');

        $this->assertNotContains($drive->id, $this->catalogueIds());
        $this->donorBooks($drive)->assertUnprocessable()->assertJsonValidationErrors('drive_id');
        $this->assertSame(AppointmentStatus::Scheduled, $held->fresh()->status);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/registration/open")
            ->assertOk()
            ->assertJsonPath('registration_open', true);

        $this->donorBooks($drive)->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'drive.registration_closed', 'auditable_id' => $drive->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'drive.registration_reopened', 'auditable_id' => $drive->id]);
    }

    public function test_the_desk_cannot_book_into_a_drive_closed_to_registration(): void
    {
        $drive = $this->drive(['registration_closed_at' => now()]);
        $donor = User::factory()->donor()->create();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/appointments', ['donor_uuid' => $donor->uuid, 'drive_id' => $drive->id, 'time_slot' => '09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('drive_id');

        $offered = collect($this->actingAs($this->staff)->getJson('/api/blood-center/appointments/options')->assertOk()->json('drives'))->pluck('id');
        $this->assertNotContains($drive->id, $offered);
    }

    public function test_registration_cannot_be_closed_on_a_draft(): void
    {
        $drive = $this->drive(['published_at' => null]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/registration/close")
            ->assertStatus(409)
            ->assertJsonPath('code', 'drive_not_published');
    }

    // --- Editing ----------------------------------------------------------------

    public function test_editing_saves_the_details_and_audits_which_fields_changed(): void
    {
        $drive = $this->drive(['max_capacity' => 60]);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload([
                'event_date' => $drive->event_date->toDateString(),
                'name' => 'UM Matina Drive (Gym)',
                'max_capacity' => 80,
                'expected_participants' => 150,
            ]))
            ->assertOk()
            ->assertJsonPath('name', 'UM Matina Drive (Gym)')
            ->assertJsonPath('capacity', 80)
            ->assertJsonPath('expected_participants', 150);

        $log = AuditLog::query()->where('action', 'drive.updated')->firstOrFail();
        $this->assertEqualsCanonicalizing(['name', 'location', 'max_capacity', 'expected_participants'], $log->context['fields']);
    }

    public function test_the_capacity_cannot_drop_below_the_bookings_held(): void
    {
        $drive = $this->drive(['max_capacity' => 10]);
        $this->book($drive);
        $this->book($drive, AppointmentStatus::Confirmed);
        // Neither of these holds a place.
        $this->book($drive, AppointmentStatus::Cancelled);
        $this->book($drive, AppointmentStatus::NoShow);

        $edit = fn (?int $capacity) => $this->actingAs($this->staff)->patchJson(
            "/api/blood-center/drives/{$drive->id}",
            $this->payload(['event_date' => $drive->event_date->toDateString(), 'max_capacity' => $capacity])
        );

        $edit(1)->assertUnprocessable()->assertJsonPath('code', 'capacity_below_bookings')->assertJsonValidationErrors('max_capacity');
        $edit(2)->assertOk()->assertJsonPath('capacity', 2)->assertJsonPath('status', 'Full');
        $edit(null)->assertOk()->assertJsonPath('capacity', null);
    }

    public function test_the_date_and_hours_are_fixed_once_a_donor_is_booked(): void
    {
        $drive = $this->drive();
        $same = ['event_date' => $drive->event_date->toDateString()];

        // Nobody booked yet: the drive can move.
        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload([...$same, 'start_time' => '09:00']))
            ->assertOk();

        $this->book($drive);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload(['event_date' => now()->addWeeks(2)->toDateString(), 'start_time' => '09:00']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'drive_schedule_locked')
            ->assertJsonValidationErrors('event_date');

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload([...$same, 'end_time' => '13:00', 'start_time' => '09:00']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'drive_schedule_locked');

        // The same day and hours, with other details changed, still save.
        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload([...$same, 'start_time' => '09:00', 'name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');
    }

    public function test_a_cancelled_or_past_drive_cannot_be_changed(): void
    {
        $cancelled = $this->drive(['cancelled_at' => now(), 'cancellation_reason' => 'Typhoon']);
        $past = $this->drive(['event_date' => now()->subWeek()->toDateString()]);

        foreach ([[$cancelled, 'drive_cancelled'], [$past, 'drive_completed']] as [$drive, $code]) {
            $this->actingAs($this->staff)
                ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload())
                ->assertStatus(409)
                ->assertJsonPath('code', $code);

            $this->actingAs($this->staff)
                ->postJson("/api/blood-center/drives/{$drive->id}/cancel", ['reason' => 'Again'])
                ->assertStatus(409)
                ->assertJsonPath('code', $code);
        }
    }

    // --- Cancelling ---------------------------------------------------------------

    public function test_cancelling_cancels_the_waiting_bookings_revokes_their_qr_and_tells_the_donors(): void
    {
        $drive = $this->drive();
        $first = $this->book($drive);
        $second = $this->book($drive);
        $earlier = $this->book($drive, AppointmentStatus::Cancelled);

        $token = DonorQrToken::factory()->create(['donor_id' => $first->donor_id]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/cancel", ['reason' => 'Venue flooded'])
            ->assertOk()
            ->assertJsonPath('status', 'Cancelled')
            ->assertJsonPath('cancellation_reason', 'Venue flooded')
            ->assertJsonPath('registration_open', false)
            ->assertJsonPath('appointments_cancelled', 2);

        $this->assertSame(AppointmentStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $second->fresh()->status);
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertDatabaseHas('mobile_events', ['id' => $drive->id, 'cancelled_by' => $this->staff->id]);

        Notification::assertSentTo(User::query()->findOrFail($first->donor_id), DriveCancelled::class);
        Notification::assertSentTo(User::query()->findOrFail($second->donor_id), DriveCancelled::class);
        Notification::assertNotSentTo(User::query()->findOrFail($earlier->donor_id), DriveCancelled::class);

        $log = AuditLog::query()->where('action', 'drive.cancelled')->firstOrFail();
        $this->assertSame(2, $log->context['appointments_cancelled']);
        $this->assertArrayNotHasKey('reason', $log->context);
    }

    public function test_a_cancelled_drive_takes_no_bookings_and_leaves_the_lists(): void
    {
        $drive = $this->drive();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/cancel", ['reason' => 'Venue flooded'])
            ->assertOk();

        $this->assertNotContains($drive->id, $this->catalogueIds());
        $this->donorBooks($drive)->assertUnprocessable()->assertJsonValidationErrors('drive_id');

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/appointments', ['donor_uuid' => User::factory()->donor()->create()->uuid, 'drive_id' => $drive->id, 'time_slot' => '09:00'])
            ->assertUnprocessable();

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/drives')
            ->assertOk()
            ->assertJsonPath('upcoming_drives_count', 0)
            ->assertJsonPath('drives.0.status', 'Cancelled');

        // Nor can paper records be filed against it.
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/paper-sheets', [
                'mobile_event_id' => $drive->id,
                'held_on' => $drive->event_date->toDateString(),
                'starts_at' => '08:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mobile_event_id');
    }

    public function test_a_cancelled_drive_is_left_off_the_dashboard(): void
    {
        $drive = $this->drive([
            'event_date' => OperationalDay::today()->addDays(2)->toDateString(),
            'cancelled_at' => now(),
        ]);

        $drives = $this->actingAs($this->staff)->getJson('/api/blood-center/collection/dashboard')->assertOk()->json('upcoming.drives');

        $this->assertNotContains($drive->id, collect($drives)->pluck('id'));
    }

    public function test_cancelling_needs_a_reason(): void
    {
        $drive = $this->drive();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/cancel", ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertNull($drive->fresh()->cancelled_at);
    }

    public function test_a_drive_with_a_donor_checked_in_cannot_be_cancelled(): void
    {
        $drive = $this->drive();
        $waiting = $this->book($drive);
        $this->book($drive, AppointmentStatus::Confirmed);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$drive->id}/cancel", ['reason' => 'Rain'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'drive_in_progress');

        $this->assertNull($drive->fresh()->cancelled_at);
        $this->assertSame(AppointmentStatus::Scheduled, $waiting->fresh()->status);
        Notification::assertNothingSent();
    }

    // --- Access ---------------------------------------------------------------

    public function test_another_centres_drive_cannot_be_changed(): void
    {
        $theirs = MobileEvent::factory()->create(['published_at' => null]);

        $this->actingAs($this->staff)->patchJson("/api/blood-center/drives/{$theirs->id}", $this->payload())->assertNotFound();
        $this->actingAs($this->staff)->postJson("/api/blood-center/drives/{$theirs->id}/publish")->assertNotFound();
        $this->actingAs($this->staff)->postJson("/api/blood-center/drives/{$theirs->id}/registration/close")->assertNotFound();
        $this->actingAs($this->staff)->postJson("/api/blood-center/drives/{$theirs->id}/cancel", ['reason' => 'x'])->assertNotFound();

        $this->assertNull($theirs->fresh()->published_at);
        $this->assertNull($theirs->fresh()->cancelled_at);
    }

    public function test_a_role_without_drives_manage_is_refused(): void
    {
        $drive = $this->drive(['published_at' => null]);
        $phlebotomist = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();

        $this->actingAs($phlebotomist)->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload())->assertForbidden();
        $this->actingAs($phlebotomist)->postJson("/api/blood-center/drives/{$drive->id}/publish")->assertForbidden();
        $this->actingAs($phlebotomist)->postJson("/api/blood-center/drives/{$drive->id}/registration/open")->assertForbidden();
        $this->actingAs($phlebotomist)->postJson("/api/blood-center/drives/{$drive->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }
}
