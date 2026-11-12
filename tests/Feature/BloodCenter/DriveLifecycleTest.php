<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use App\Models\DrivePreRegistration;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\BloodDriveScheduled;
use App\Notifications\DriveCancelled;
use App\Notifications\DriveRescheduled;
use App\Notifications\DriveRescheduledForRegistrant;
use App\Service\BloodDriveAnnouncer;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A drive's lifecycle (drive pre-registration, phase 1): no booking limit and
 * one live pre-registered count (project owner, 2026-11-12), drafts and
 * publishing, closing registration, editing, and cancelling with the donors
 * told.
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

    // --- No limit, one live count (project owner, 2026-11-12) -------------------

    public function test_a_drive_has_no_capacity_or_estimate_only_the_live_count(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['max_capacity' => 5, 'expected_participants' => 120]))
            ->assertCreated()
            ->assertJsonMissingPath('capacity')
            ->assertJsonMissingPath('expected_participants')
            ->assertJsonPath('pre_registered_count', 0);
    }

    public function test_every_booking_is_taken_and_raises_the_pre_registered_count(): void
    {
        $drive = $this->drive();

        foreach (range(1, 4) as $i) {
            $this->donorBooks($drive)->assertCreated();
        }

        $listed = $this->actingAs($this->staff)->getJson('/api/blood-center/drives')->assertOk()->json('drives.0');
        $this->assertSame(4, $listed['pre_registered_count']);
        $this->assertSame('Upcoming', $listed['status']);
    }

    public function test_a_cancelled_booking_leaves_the_pre_registered_count(): void
    {
        $drive = $this->drive();
        $this->book($drive);
        $this->book($drive, AppointmentStatus::Cancelled);

        $this->actingAs($this->staff)->getJson('/api/blood-center/drives')
            ->assertOk()
            ->assertJsonPath('drives.0.pre_registered_count', 1);
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
        $drive = $this->drive();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload([
                'event_date' => $drive->event_date->toDateString(),
                'name' => 'UM Matina Drive (Gym)',
            ]))
            ->assertOk()
            ->assertJsonPath('name', 'UM Matina Drive (Gym)')
            ->assertJsonMissingPath('capacity');

        $log = AuditLog::query()->where('action', 'drive.updated')->firstOrFail();
        $this->assertEqualsCanonicalizing(['name', 'location'], $log->context['fields']);
    }

    public function test_an_edit_cannot_put_back_a_booking_limit(): void
    {
        $drive = $this->drive();
        $this->book($drive);
        $this->book($drive);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload(['event_date' => $drive->event_date->toDateString(), 'max_capacity' => 1]))
            ->assertOk()
            ->assertJsonMissingPath('capacity');

        $this->donorBooks($drive)->assertCreated();
    }

    // --- No duplicates: same day, same venue (project owner, 2026-11-12) -------

    public function test_a_second_drive_on_the_same_day_at_the_same_venue_is_refused(): void
    {
        $existing = $this->drive(['name' => 'UM Matina Drive', 'location' => 'University of Mindanao, Matina, Davao City']);

        // Another name, other hours, and the venue typed with other capitals and spaces.
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload([
                'name' => 'UM Bloodletting',
                'location' => '  university of mindanao,  Matina, Davao City ',
                'start_time' => '13:00',
                'end_time' => '17:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'drive_duplicate')
            ->assertJsonPath(
                'errors.location.0',
                'A drive at University of Mindanao, Matina, Davao City is already set for '
                    .$existing->event_date->format('j M Y').' (UM Matina Drive). Edit that drive instead.'
            );

        $this->assertSame(1, MobileEvent::query()->count());
    }

    public function test_a_draft_counts_as_a_duplicate_but_a_cancelled_drive_does_not(): void
    {
        $draft = MobileEvent::factory()->draft()->create([
            'facility_id' => $this->facility->id,
            'location' => 'SM Lanang Premier, Davao City',
            'event_date' => now()->addWeek()->toDateString(),
        ]);
        MobileEvent::factory()->cancelled()->create([
            'facility_id' => $this->facility->id,
            'location' => 'University of Mindanao, Matina, Davao City',
            'event_date' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['location' => $draft->location]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'drive_duplicate');

        // The cancelled one can be scheduled again on its day.
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertCreated();
    }

    public function test_another_day_another_venue_or_another_centre_is_not_a_duplicate(): void
    {
        $this->drive(['location' => 'University of Mindanao, Matina, Davao City']);
        MobileEvent::factory()->create([
            'facility_id' => Facility::factory()->approved()->create()->id,
            'location' => 'SM Lanang Premier, Davao City',
            'event_date' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['event_date' => now()->addWeeks(2)->toDateString()]))
            ->assertCreated();
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['location' => 'University of Mindanao, Bolton, Davao City']))
            ->assertCreated();
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['location' => 'SM Lanang Premier, Davao City']))
            ->assertCreated();
    }

    public function test_an_edit_cannot_move_a_drive_onto_another_at_the_same_venue(): void
    {
        $this->drive(['location' => 'University of Mindanao, Matina, Davao City']);
        $later = $this->drive([
            'location' => 'University of Mindanao, Matina, Davao City',
            'event_date' => now()->addWeeks(2)->toDateString(),
        ]);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$later->id}", $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'drive_duplicate');

        $this->assertSame(now()->addWeeks(2)->toDateString(), $later->fresh()->event_date->toDateString());
    }

    public function test_an_edit_that_keeps_the_day_and_venue_is_never_refused(): void
    {
        // A pair from before the rule: renaming either one still saves.
        $this->drive(['location' => 'University of Mindanao, Matina, Davao City']);
        $twin = $this->drive(['location' => 'University of Mindanao, Matina, Davao City']);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$twin->id}", $this->payload([
                'name' => 'UM Matina Drive (Gym)',
                'location' => 'UNIVERSITY OF MINDANAO, Matina, Davao City',
            ]))
            ->assertOk()
            ->assertJsonPath('name', 'UM Matina Drive (Gym)');
    }

    /**
     * "Should a booked drive be movable with the donors notified - yes"
     * (project owner, 2026-11-12).
     */
    public function test_moving_a_booked_drive_moves_its_bookings_and_tells_everyone(): void
    {
        $drive = $this->drive();
        $first = $this->book($drive);
        $second = $this->book($drive);
        $second->update(['appointment_datetime' => $drive->event_date->copy()->setTime(11, 30)]);
        $cancelled = $this->book($drive, AppointmentStatus::Cancelled);
        $before = $cancelled->appointment_datetime->toDateTimeString();
        DrivePreRegistration::factory()->create(['mobile_event_id' => $drive->id, 'email' => 'listed@example.com']);
        // No questionnaire covers the new day, so the QR goes.
        $token = DonorQrToken::factory()->create(['donor_id' => $first->donor_id]);

        $newDate = now()->addWeeks(3)->toDateString();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload([
                'event_date' => $newDate,
                'start_time' => '13:00',
                'end_time' => '17:00',
            ]))
            ->assertOk()
            ->assertJsonPath('event_date', $newDate)
            ->assertJsonPath('appointments_moved', 2);

        // Both fall outside the new hours, so both take the new start.
        $this->assertSame("{$newDate} 13:00:00", $first->fresh()->appointment_datetime->toDateTimeString());
        $this->assertSame("{$newDate} 13:00:00", $second->fresh()->appointment_datetime->toDateTimeString());
        $this->assertSame($before, $cancelled->fresh()->appointment_datetime->toDateTimeString());
        $this->assertNotNull($token->fresh()->revoked_at);

        Notification::assertSentTo(User::query()->findOrFail($first->donor_id), DriveRescheduled::class);
        Notification::assertSentTo(User::query()->findOrFail($second->donor_id), DriveRescheduled::class);
        Notification::assertNotSentTo(User::query()->findOrFail($cancelled->donor_id), DriveRescheduled::class);
        Notification::assertSentOnDemandTimes(DriveRescheduledForRegistrant::class, 1);

        $log = AuditLog::query()->where('action', 'drive.updated')->sole();
        $this->assertSame(2, $log->context['appointments_moved']);
        $this->assertSame(2, $log->context['donors_notified']);
        $this->assertSame(1, $log->context['registrants_notified']);
    }

    public function test_a_moved_booking_keeps_its_time_when_it_falls_inside_the_new_hours(): void
    {
        $drive = $this->drive();
        $booking = $this->book($drive);
        $newDate = now()->addWeeks(2)->toDateString();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload(['event_date' => $newDate]))
            ->assertOk()
            ->assertJsonPath('appointments_moved', 1);

        $this->assertSame("{$newDate} 09:00:00", $booking->fresh()->appointment_datetime->toDateTimeString());
    }

    public function test_the_date_cannot_move_while_a_donor_is_checked_in(): void
    {
        $drive = $this->drive();
        $this->book($drive, AppointmentStatus::Confirmed);
        $waiting = $this->book($drive);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload(['event_date' => now()->addWeeks(2)->toDateString()]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'drive_in_progress')
            ->assertJsonValidationErrors('event_date');

        $this->assertSame($drive->event_date->toDateString(), $drive->fresh()->event_date->toDateString());
        $this->assertSame($drive->event_date->toDateString(), $waiting->fresh()->appointment_datetime->toDateString());
        Notification::assertNothingSent();

        // The hours alone may still change on the day.
        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload(['event_date' => $drive->event_date->toDateString(), 'end_time' => '13:00']))
            ->assertOk();
    }

    public function test_an_edit_that_does_not_move_the_drive_tells_nobody(): void
    {
        $drive = $this->drive();
        $booking = $this->book($drive);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/drives/{$drive->id}", $this->payload(['event_date' => $drive->event_date->toDateString(), 'name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('name', 'Renamed')
            ->assertJsonPath('appointments_moved', 0);

        $this->assertSame('09:00', $booking->fresh()->appointment_datetime->format('H:i'));
        Notification::assertNotSentTo(User::query()->findOrFail($booking->donor_id), DriveRescheduled::class);
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
