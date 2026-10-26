<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\AppointmentScheduled;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Appointments page and the desk booking a donor who phones in
 * (project owner, 2026-10-17).
 *
 * What is locked in: a booking taken by phone obeys the portal's own rules,
 * a drive (mobile drive) booking stays inside that drive, a booking a visit was
 * opened against is never deleted, and the list is this centre's only.
 */
class StaffAppointmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $desk;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create(['slot_capacity' => 2, 'slot_interval_minutes' => 60, 'slots_start_at' => '08:00', 'slots_end_at' => '15:00']);
        $this->desk = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    private function day(int $fromToday): string
    {
        return OperationalDay::today()->addDays($fromToday)->toDateString();
    }

    private function appointment(int $fromToday, AppointmentStatus $status = AppointmentStatus::Scheduled, array $extra = []): DonationAppointment
    {
        return DonationAppointment::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => $this->facility->id,
            // Stored as a booking stores it: the operational date's wall clock.
            'appointment_datetime' => Carbon::parse($this->day($fromToday).' 09:00'),
            'status' => $status,
            ...$extra,
        ]);
    }

    private function book(User $donor, array $payload = []): TestResponse
    {
        return $this->actingAs($this->desk)->postJson('/api/blood-center/appointments', [
            'donor_uuid' => $donor->uuid,
            'date' => $this->day(3),
            'time_slot' => '09:00',
            ...$payload,
        ]);
    }

    // --- The list -----------------------------------------------------------

    public function test_the_list_is_this_centres_newest_day_first_earliest_slot_first(): void
    {
        $older = $this->appointment(-5);
        $laterSlot = $this->appointment(2, extra: ['appointment_datetime' => Carbon::parse($this->day(2).' 11:00')]);
        $earlySlot = $this->appointment(2);
        DonationAppointment::factory()->create(['appointment_datetime' => now()->addDays(2)]);

        $rows = $this->actingAs($this->desk)->getJson('/api/blood-center/appointments')->assertOk()->json('data');

        $this->assertSame([$earlySlot->id, $laterSlot->id, $older->id], array_column($rows, 'id'));
        $this->assertSame('APT-'.str_pad((string) $earlySlot->id, 6, '0', STR_PAD_LEFT), $rows[0]['code']);
        $this->assertSame('09:00', $rows[0]['slot_start']);
        $this->assertSame('10:00', $rows[0]['slot_end']);
        $this->assertSame($this->facility->name, $rows[0]['place']);
    }

    public function test_it_searches_donors_drives_and_codes_and_filters_by_date_and_status(): void
    {
        $drive = MobileEvent::factory()->create(['facility_id' => $this->facility->id, 'name' => 'University Youth Drive', 'event_date' => $this->day(4)]);
        $atDrive = $this->appointment(4, extra: ['event_id' => $drive->id]);
        $named = $this->appointment(1);
        $named->donorProfile->donor->forceFill(['first_name' => 'Divya', 'last_name' => 'Nair'])->save();
        $noShow = $this->appointment(-2, AppointmentStatus::NoShow);

        $ids = fn (array $query) => array_column($this->actingAs($this->desk)->getJson('/api/blood-center/appointments?'.http_build_query($query))->assertOk()->json('data'), 'id');

        $this->assertSame([$atDrive->id], $ids(['search' => 'youth drive']));
        $this->assertSame([$named->id], $ids(['search' => 'divya nair']));
        $this->assertSame([$noShow->id], $ids(['search' => 'APT-'.str_pad((string) $noShow->id, 6, '0', STR_PAD_LEFT)]));
        $this->assertSame([$named->id], $ids(['from' => $this->day(0), 'to' => $this->day(2)]));
        $this->assertSame([$noShow->id], $ids(['status' => 'no_show']));
    }

    public function test_the_tick_is_offered_from_the_appointment_day_on(): void
    {
        $today = $this->appointment(0);
        $tomorrow = $this->appointment(1);
        $yesterday = $this->appointment(-1);
        $underway = $this->appointment(0, AppointmentStatus::Confirmed);

        $rows = collect($this->actingAs($this->desk)->getJson('/api/blood-center/appointments')->json('data'))->keyBy('id');

        $this->assertTrue($rows[$today->id]['can']['collect']);
        $this->assertTrue($rows[$underway->id]['can']['collect']);
        $this->assertFalse($rows[$tomorrow->id]['can']['collect']);
        // A late arrival too (project owner, 2026-10-17).
        $this->assertTrue($rows[$yesterday->id]['can']['collect']);

        $this->assertTrue($rows[$yesterday->id]['can']['no_show']);
        $this->assertFalse($rows[$tomorrow->id]['can']['no_show']);
        $this->assertTrue($rows[$tomorrow->id]['can']['cancel']);
    }

    public function test_the_tick_marks_the_booking_collected(): void
    {
        $late = $this->appointment(-1);
        $future = $this->appointment(2);

        $this->actingAs($this->desk)
            ->postJson("/api/blood-center/appointments/{$late->id}/collected")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_label', 'Collected');

        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.marked_collected', 'auditable_id' => $late->id]);

        // Not twice, and not for a day still to come.
        $this->actingAs($this->desk)->postJson("/api/blood-center/appointments/{$late->id}/collected")->assertStatus(409);
        $this->actingAs($this->desk)->postJson("/api/blood-center/appointments/{$future->id}/collected")
            ->assertUnprocessable()->assertJsonPath('code', 'appointment_not_due');

        // A collected booking is kept, like one the counter closed.
        $this->actingAs($this->desk)->deleteJson("/api/blood-center/appointments/{$late->id}")->assertStatus(409);
    }

    // --- Booking by phone ---------------------------------------------------

    public function test_the_desk_books_a_donor_who_phoned_and_the_donor_is_told(): void
    {
        $donor = User::factory()->donor()->create();

        $this->book($donor, ['notes' => 'Called at 10am; prefers the morning.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.slot_start', '09:00')
            ->assertJsonPath('data.notes', 'Called at 10am; prefers the morning.')
            ->assertJsonPath('data.booked_by', trim($this->desk->first_name.' '.$this->desk->last_name));

        Notification::assertSentTo($donor, AppointmentScheduled::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.booked_by_staff', 'actor_id' => $this->desk->id]);

        // The booking makes them this centre's donor.
        $uuids = collect($this->actingAs($this->desk)->getJson('/api/blood-center/donors')->json('data'))->pluck('uuid');
        $this->assertContains($donor->uuid, $uuids);
    }

    public function test_a_phone_booking_obeys_the_portals_rules(): void
    {
        $donor = User::factory()->donor()->create();

        $this->book($donor, ['time_slot' => '09:30'])->assertUnprocessable()->assertJsonValidationErrors('time_slot');
        $this->book($donor, ['date' => $this->day(-1)])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->book($donor)->assertCreated();
        $this->book($donor, ['date' => $this->day(5)])->assertStatus(409)->assertJsonPath('code', 'duplicate_appointment');

        // Two places per slot: the third booking is refused.
        $this->book(User::factory()->donor()->create())->assertCreated();
        $this->book(User::factory()->donor()->create())->assertStatus(409)->assertJsonPath('code', 'slot_unavailable');
    }

    public function test_the_donation_interval_is_explained_to_staff_not_the_donor(): void
    {
        $donor = User::factory()->donor()->create();
        Donation::factory()->create(['donor_id' => $donor->id, 'facility_id' => $this->facility->id, 'donation_date' => now()->subDays(10), 'status' => DonationStatus::Completed]);

        $this->book($donor)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'below_min_interval')
            ->assertJsonPath('message', fn (string $m): bool => str_starts_with($m, 'This donor cannot donate again until'));
    }

    public function test_a_deferred_or_suspended_donor_is_not_booked(): void
    {
        $deferred = User::factory()->donor()->create();
        $visit = Donation::factory()->create(['donor_id' => $deferred->id, 'facility_id' => $this->facility->id, 'donation_date' => now()->subYear(), 'status' => DonationStatus::Rejected]);
        DonationScreening::factory()->permanentlyDeferred()->create(['donation_id' => $visit->id, 'facility_id' => $this->facility->id]);

        $this->book($deferred)->assertUnprocessable()->assertJsonPath('code', 'donor_deferred');

        $suspended = User::factory()->donor()->create();
        $suspended->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->book($suspended)->assertUnprocessable()->assertJsonPath('code', 'account_closed');
    }

    public function test_a_drive_booking_takes_the_drives_date_and_stays_within_its_hours_and_places(): void
    {
        $drive = MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => $this->day(6),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'max_capacity' => 1,
        ]);

        $options = $this->actingAs($this->desk)->getJson("/api/blood-center/appointments/options?drive_id={$drive->id}")->assertOk()->json();
        $this->assertSame($this->day(6), $options['date']);
        $this->assertSame(['10:00', '11:00'], array_column($options['slots'], 'time'));
        $this->assertSame($drive->id, $options['drives'][0]['id']);

        $this->book(User::factory()->donor()->create(), ['drive_id' => $drive->id, 'date' => null, 'time_slot' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('time_slot');

        $this->book(User::factory()->donor()->create(), ['drive_id' => $drive->id, 'date' => null, 'time_slot' => '10:00'])
            ->assertCreated()
            ->assertJsonPath('data.date', $this->day(6))
            ->assertJsonPath('data.place', $drive->name);

        $this->book(User::factory()->donor()->create(), ['drive_id' => $drive->id, 'date' => null, 'time_slot' => '11:00'])
            ->assertStatus(409)->assertJsonPath('code', 'drive_full');

        $elsewhere = MobileEvent::factory()->create(['event_date' => $this->day(6)]);
        $this->book(User::factory()->donor()->create(), ['drive_id' => $elsewhere->id, 'date' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('drive_id');
    }

    // --- Cancel and delete -------------------------------------------------

    public function test_only_a_scheduled_appointment_is_cancelled(): void
    {
        $scheduled = $this->appointment(2);
        $underway = $this->appointment(0, AppointmentStatus::Confirmed);

        $this->actingAs($this->desk)->postJson("/api/blood-center/appointments/{$scheduled->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($this->desk)->postJson("/api/blood-center/appointments/{$underway->id}/cancel")
            ->assertStatus(409);
    }

    public function test_a_booking_with_a_visit_is_never_deleted(): void
    {
        $mistake = $this->appointment(3);
        $visited = $this->appointment(-1, AppointmentStatus::Completed);
        Donation::factory()->create([
            'donor_id' => $visited->donor_id,
            'facility_id' => $this->facility->id,
            'appointment_id' => $visited->id,
            'donation_date' => now()->subDay(),
        ]);

        $this->actingAs($this->desk)->deleteJson("/api/blood-center/appointments/{$mistake->id}")->assertOk();
        $this->assertDatabaseMissing('donation_appointments', ['id' => $mistake->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.deleted', 'auditable_id' => $mistake->id]);

        $this->actingAs($this->desk)->deleteJson("/api/blood-center/appointments/{$visited->id}")
            ->assertStatus(409)->assertJsonPath('code', 'appointment_has_visit');
        $this->assertDatabaseHas('donation_appointments', ['id' => $visited->id]);
    }

    public function test_another_centres_appointment_is_not_found(): void
    {
        $theirs = DonationAppointment::factory()->create();

        $this->actingAs($this->desk)->postJson("/api/blood-center/appointments/{$theirs->id}/cancel")->assertNotFound();
        $this->actingAs($this->desk)->deleteJson("/api/blood-center/appointments/{$theirs->id}")->assertNotFound();
    }

    // --- Who may do what ---------------------------------------------------

    public function test_the_physician_reads_the_list_but_books_nothing(): void
    {
        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();

        $this->actingAs($physician)->getJson('/api/blood-center/appointments')->assertOk();
        $this->actingAs($physician)->postJson('/api/blood-center/appointments', [])->assertForbidden();

        $row = $this->actingAs($physician)->getJson('/api/blood-center/appointments')->json('data');
        $this->assertSame([], $row);
    }

    public function test_the_export_is_the_filtered_list_as_csv(): void
    {
        $this->appointment(1);

        $csv = $this->actingAs($this->desk)->get('/api/blood-center/appointments/export')->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFAppointmentID,Donor,DonorID,BloodGroup,Phone,Date,Slot,DriveCenter,Status", $csv);
        $this->assertStringContainsString('"09:00 - 10:00"', $csv);
    }
}
