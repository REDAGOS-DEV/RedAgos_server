<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationScreening;
use App\Models\DonorQrToken;
use App\Models\DrivePreRegistration;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\PaperDonationSheet;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A drive's roster and its day (drive pre-registration, phases 3, 4 and 6;
 * project owner, 2026-11-12): one row per person, statuses taken from the
 * records that produce them, figures that reconcile, the self-check as a
 * status only, and check-in and walk-ins through the appointment path so the
 * donation reaches the drive.
 */
class DriveRosterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    private MobileEvent $drive;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create(['is_accepting_donations' => true, 'slot_interval_minutes' => 60]);
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
        // Today's drive: check-in and walk-ins happen on the drive's own day.
        $this->drive = MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => OperationalDay::todayAsDate(),
            'start_time' => '08:00',
            'end_time' => '23:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(?User $donor = null, array $attributes = []): DonationAppointment
    {
        return DonationAppointment::factory()->create([
            'donor_id' => ($donor ?? User::factory()->donor()->create())->id,
            'facility_id' => $this->facility->id,
            'event_id' => $this->drive->id,
            'appointment_datetime' => Carbon::parse(OperationalDay::todayAsDate().' 09:00'),
            'status' => AppointmentStatus::Scheduled,
            ...$attributes,
        ]);
    }

    /**
     * A visit at the counter: collected (the factory draws the bag), or
     * deferred at screening.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function visit(DonationAppointment $booking, bool $deferred = false, array $attributes = []): Donation
    {
        if (! $deferred) {
            return Donation::factory()->create([
                'donor_id' => $booking->donor_id,
                'facility_id' => $this->facility->id,
                'appointment_id' => $booking->id,
                'donation_date' => now(),
                ...$attributes,
            ]);
        }

        $donation = Donation::factory()->create([
            'donor_id' => $booking->donor_id,
            'facility_id' => $this->facility->id,
            'appointment_id' => $booking->id,
            'donation_date' => now(),
            'status' => DonationStatus::Rejected,
            ...$attributes,
        ]);
        DonationScreening::factory()->deferred()->create(['donation_id' => $donation->id, 'facility_id' => $this->facility->id]);

        return $donation;
    }

    private function roster(): TestResponse
    {
        return $this->actingAs($this->staff)->getJson("/api/blood-center/drives/{$this->drive->id}/registrations")->assertOk();
    }

    /**
     * @return array<string, array<string, mixed>> rows keyed by key
     */
    private function rows(): array
    {
        return collect($this->roster()->json('data'))->keyBy('key')->all();
    }

    private function paperSheet(): PaperDonationSheet
    {
        return PaperDonationSheet::query()->create([
            'facility_id' => $this->facility->id,
            'mobile_event_id' => $this->drive->id,
            'drive_name' => $this->drive->name,
            'held_on' => $this->drive->event_date->toDateString(),
            'starts_at' => '08:00',
            'created_by' => $this->staff->id,
        ]);
    }

    // --- The roster ------------------------------------------------------------

    public function test_everyone_appears_once_with_where_they_came_from(): void
    {
        $portalDonor = User::factory()->donor()->create();
        $portal = $this->booking($portalDonor);
        DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'donor_id' => $portalDonor->id, 'appointment_id' => $portal->id]);
        $listed = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id]);
        $partner = DrivePreRegistration::factory()->fromPartner()->create(['mobile_event_id' => $this->drive->id]);
        $desk = $this->booking(null, ['booked_by' => $this->staff->id]);
        $walkIn = $this->booking(null, ['walk_in' => true, 'booked_by' => $this->staff->id, 'status' => AppointmentStatus::Confirmed]);
        $paper = Donation::factory()->create(['facility_id' => $this->facility->id, 'paper_sheet_id' => $this->paperSheet()->id]);

        $rows = $this->rows();

        $this->assertCount(6, $rows);
        // The list entry tied to the portal booking is shown as that booking.
        $this->assertArrayNotHasKey("entry-{$listed->id}-tied", $rows);
        $this->assertSame('self', $rows["booking-{$portal->id}"]['source']);
        $this->assertSame('self', $rows["entry-{$listed->id}"]['source']);
        $this->assertSame('partner', $rows["entry-{$partner->id}"]['source']);
        $this->assertSame($partner->partnerSubmission->organisation_name, $rows["entry-{$partner->id}"]['source_label']);
        $this->assertSame('desk', $rows["booking-{$desk->id}"]['source']);
        $this->assertSame('walk_in', $rows["booking-{$walkIn->id}"]['source']);
        $this->assertSame('paper', $rows["paper-{$paper->id}"]['source']);
    }

    public function test_statuses_come_only_from_the_records_that_produce_them(): void
    {
        $booked = $this->booking();
        $checkedIn = $this->booking(null, ['status' => AppointmentStatus::Confirmed]);
        $noShow = $this->booking(null, ['status' => AppointmentStatus::NoShow]);
        $cancelled = $this->booking(null, ['status' => AppointmentStatus::Cancelled]);
        // Completed by the Appointments page's tick, with no donation: came, nothing more.
        $ticked = $this->booking(null, ['status' => AppointmentStatus::Completed]);
        $deferred = $this->booking(null, ['status' => AppointmentStatus::Completed]);
        $this->visit($deferred, deferred: true);
        $collected = $this->booking(null, ['status' => AppointmentStatus::Completed]);
        $this->visit($collected);
        $listed = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id]);

        $rows = $this->rows();

        $this->assertSame('booked', $rows["booking-{$booked->id}"]['status']);
        $this->assertSame('checked_in', $rows["booking-{$checkedIn->id}"]['status']);
        $this->assertSame('no_show', $rows["booking-{$noShow->id}"]['status']);
        $this->assertSame('cancelled', $rows["booking-{$cancelled->id}"]['status']);
        $this->assertSame('checked_in', $rows["booking-{$ticked->id}"]['status']);
        $this->assertSame('deferred', $rows["booking-{$deferred->id}"]['status']);
        $this->assertSame('collected', $rows["booking-{$collected->id}"]['status']);
        // A list entry is a plan, never an outcome.
        $this->assertSame('pre_registered', $rows["entry-{$listed->id}"]['status']);
    }

    public function test_the_figures_reconcile_with_the_rows_and_the_drives_list(): void
    {
        DrivePreRegistration::factory()->count(2)->create(['mobile_event_id' => $this->drive->id]);
        DrivePreRegistration::factory()->withdrawn()->create(['mobile_event_id' => $this->drive->id]);
        $this->booking();
        $this->booking(null, ['status' => AppointmentStatus::NoShow]);
        $this->booking(null, ['status' => AppointmentStatus::Cancelled]);
        $this->visit($this->booking(null, ['status' => AppointmentStatus::Completed]));
        $this->visit($this->booking(null, ['status' => AppointmentStatus::Completed]), deferred: true);
        $this->visit($this->booking(null, ['walk_in' => true, 'status' => AppointmentStatus::Completed]));
        Donation::factory()->create(['facility_id' => $this->facility->id, 'paper_sheet_id' => $this->paperSheet()->id, 'donation_date' => now()]);

        $summary = $this->roster()->json('summary');

        // Planning: 2 on the list + 4 bookings made ahead that stand.
        $this->assertSame(2, $summary['listed']);
        $this->assertSame(4, $summary['booked']);
        $this->assertSame(6, $summary['pre_registered_donors']);
        $this->assertSame($summary['listed'] + $summary['booked'], $summary['pre_registered_donors']);
        $this->assertSame(1, $summary['withdrawn']);
        $this->assertSame(1, $summary['cancelled']);
        // On the day: the walk-in booking and the paper donation came unannounced.
        $this->assertSame(2, $summary['walk_ins']);
        $this->assertSame(1, $summary['no_show']);
        $this->assertSame(1, $summary['deferred']);
        $this->assertSame(3, $summary['collected']);
        $this->assertSame($summary['deferred'] + $summary['collected'], $summary['checked_in']);

        $listed = $this->actingAs($this->staff)->getJson('/api/blood-center/drives')->json('drives.0');
        $this->assertSame($summary['pre_registered_donors'], $listed['pre_registered_count']);
        $this->assertSame(
            $summary['collected'],
            $this->actingAs($this->staff)->getJson('/api/blood-center/drives')->json('units_collected_month')
        );
    }

    public function test_the_self_check_is_a_status_from_the_portal_questionnaire_and_nothing_more(): void
    {
        $cleared = User::factory()->donor()->create();
        EligibilityScreening::factory()->create(['donor_id' => $cleared->id, 'deferral_reasons' => []]);
        $flagged = User::factory()->donor()->create();
        EligibilityScreening::factory()->deferred()->create(['donor_id' => $flagged->id]);
        $deferred = User::factory()->donor()->create();
        EligibilityScreening::factory()->create(['donor_id' => $deferred->id]);
        DonationScreening::factory()->permanentlyDeferred()->create([
            'donation_id' => Donation::factory()->rejected()->create(['donor_id' => $deferred->id, 'facility_id' => $this->facility->id])->id,
        ]);
        $none = User::factory()->donor()->create();

        $bookings = collect([$cleared, $flagged, $deferred, $none])->mapWithKeys(fn (User $donor): array => [$donor->id => $this->booking($donor)->id]);
        $noAccount = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id]);

        $response = $this->roster();
        $rows = collect($response->json('data'))->keyBy('key');

        $this->assertSame('cleared', $rows["booking-{$bookings[$cleared->id]}"]['self_check']);
        $this->assertSame('follow_up', $rows["booking-{$bookings[$flagged->id]}"]['self_check']);
        $this->assertSame('follow_up', $rows["booking-{$bookings[$deferred->id]}"]['self_check']);
        $this->assertSame('not_completed', $rows["booking-{$bookings[$none->id]}"]['self_check']);
        $this->assertSame('not_completed', $rows["entry-{$noAccount->id}"]['self_check']);

        // A status only: no answers, reasons or outcomes reach the list.
        foreach (['deferral_reasons', 'answers', 'deferral_reason', 'outcome', 'Haemoglobin'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_a_portal_booking_is_counted_once_with_the_donors_own_list_entry(): void
    {
        $this->drive->update(['event_date' => now()->addWeek()->toDateString()]);
        $donor = User::factory()->donor()->create(['email' => 'ana.reyes@example.com']);
        $entry = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => 'ana.reyes@example.com']);

        $this->roster()->assertJsonPath('summary.pre_registered_donors', 1);

        $this->actingAs($donor)
            ->postJson('/api/donors/appointments', ['type' => 'mobile', 'drive_id' => $this->drive->id, 'time_slot' => '09:00'])
            ->assertCreated();

        $entry->refresh();
        $this->assertNotNull($entry->appointment_id);
        $this->assertSame($donor->id, $entry->donor_id);
        $this->roster()
            ->assertJsonPath('summary.pre_registered_donors', 1)
            ->assertJsonPath('summary.booked', 1)
            ->assertJsonPath('summary.listed', 0);
    }

    // --- Check-in at the drive ------------------------------------------------

    public function test_someone_on_the_list_is_checked_in_through_a_booking_the_counter_picks_up(): void
    {
        $donor = User::factory()->donor()->create();
        $entry = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'donor_id' => $donor->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/registrations/{$entry->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.donor_uuid', $donor->uuid)
            ->assertJsonPath('data.walk_in', false);

        $booking = DonationAppointment::query()->where('event_id', $this->drive->id)->sole();
        $this->assertSame(AppointmentStatus::Confirmed, $booking->status);
        $this->assertSame($this->staff->id, $booking->booked_by);
        $this->assertSame($booking->id, $entry->fresh()->appointment_id);

        // The counter finds the booking, and the donation it opens names it.
        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/collection/arrival/{$donor->uuid}")
            ->assertOk()
            ->assertJsonPath('data.appointment.id', $booking->id);
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $donor->uuid, 'appointment_id' => $booking->id])
            ->assertCreated();
        $this->assertSame($booking->id, Donation::query()->where('donor_id', $donor->id)->value('appointment_id'));

        $this->roster()
            ->assertJsonPath('summary.pre_registered_donors', 1)
            ->assertJsonPath('summary.checked_in', 1)
            ->assertJsonPath('summary.walk_ins', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'drive.checked_in', 'actor_id' => $this->staff->id]);
    }

    public function test_check_in_needs_a_linked_donor_the_drives_day_and_a_drive_that_stands(): void
    {
        $unlinked = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id]);
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/registrations/{$unlinked->id}/check-in")
            ->assertStatus(409)->assertJsonPath('code', 'not_matched');

        $tomorrow = MobileEvent::factory()->create(['facility_id' => $this->facility->id, 'event_date' => now()->addDays(3)->toDateString()]);
        $entry = DrivePreRegistration::factory()->create(['mobile_event_id' => $tomorrow->id, 'donor_id' => User::factory()->donor()->create()->id]);
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$tomorrow->id}/registrations/{$entry->id}/check-in")
            ->assertStatus(409)->assertJsonPath('code', 'not_drive_day');

        $this->drive->update(['cancelled_at' => now()]);
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/walk-ins", ['donor_uuid' => User::factory()->donor()->create()->uuid])
            ->assertStatus(409)->assertJsonPath('code', 'drive_cancelled');

        $this->assertSame(0, DonationAppointment::query()->count());
    }

    public function test_a_walk_in_is_checked_in_on_a_walk_in_booking_once(): void
    {
        $donor = User::factory()->donor()->create();

        $first = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/walk-ins", ['donor_uuid' => $donor->uuid])
            ->assertCreated()
            ->assertJsonPath('data.walk_in', true)
            ->json('data.appointment_id');
        $again = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/walk-ins", ['donor_uuid' => $donor->uuid])
            ->assertCreated()
            ->json('data.appointment_id');

        $this->assertSame($first, $again);
        $this->roster()
            ->assertJsonPath('summary.walk_ins', 1)
            ->assertJsonPath('summary.checked_in', 1)
            ->assertJsonPath('summary.pre_registered_donors', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'drive.walk_in']);
    }

    public function test_a_booked_donor_at_the_walk_in_desk_is_checked_in_on_their_booking(): void
    {
        $donor = User::factory()->donor()->create();
        $booking = $this->booking($donor);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/walk-ins", ['donor_uuid' => $donor->uuid])
            ->assertCreated()
            ->assertJsonPath('data.appointment_id', $booking->id)
            ->assertJsonPath('data.walk_in', false);

        $this->assertSame(AppointmentStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_walk_in_holding_another_booking_is_refused(): void
    {
        $donor = User::factory()->donor()->create();
        DonationAppointment::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'appointment_datetime' => now()->addDays(4),
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/walk-ins", ['donor_uuid' => $donor->uuid])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_appointment');
    }

    public function test_check_in_belongs_to_the_counter_roles_and_the_callers_own_drives(): void
    {
        // The laboratory holds no check-in ability.
        $technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $donor = User::factory()->donor()->create();

        $this->actingAs($technologist)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/walk-ins", ['donor_uuid' => $donor->uuid])
            ->assertForbidden();

        $theirs = MobileEvent::factory()->create(['event_date' => OperationalDay::todayAsDate()]);
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$theirs->id}/walk-ins", ['donor_uuid' => $donor->uuid])
            ->assertNotFound();
    }

    // --- The existing paths still land on the drive -----------------------------

    public function test_a_qr_check_in_of_a_drive_booking_shows_as_checked_in(): void
    {
        $donor = User::factory()->donor()->create();
        $booking = $this->booking($donor);
        $raw = Str::random(40);
        DonorQrToken::factory()->create([
            'donor_id' => $donor->id,
            'token_hash' => hash('sha256', $raw),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('data.appointment.id', $booking->id);

        $this->assertSame('checked_in', $this->rows()["booking-{$booking->id}"]['status']);
    }

    public function test_a_no_show_marked_at_the_counter_is_counted_as_one(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->staff)->postJson("/api/blood-center/appointments/{$booking->id}/no-show")->assertOk();

        $this->roster()
            ->assertJsonPath('summary.no_show', 1)
            // Still pre-registered: they said they were coming.
            ->assertJsonPath('summary.pre_registered_donors', 1);
    }

    public function test_paper_records_land_on_their_booking_or_count_as_walk_ins(): void
    {
        $sheet = $this->paperSheet();
        $booking = $this->booking(null, ['status' => AppointmentStatus::Completed]);
        // Typed in from paper for a donor who had booked: the booking's visit.
        $this->visit($booking, attributes: ['paper_sheet_id' => $sheet->id]);
        // Typed in for someone who came unannounced.
        $walkIn = Donation::factory()->create(['facility_id' => $this->facility->id, 'paper_sheet_id' => $sheet->id]);

        $rows = $this->rows();
        $summary = $this->roster()->json('summary');

        $this->assertSame('collected', $rows["booking-{$booking->id}"]['status']);
        $this->assertSame('collected', $rows["paper-{$walkIn->id}"]['status']);
        $this->assertSame(1, $summary['walk_ins']);
        $this->assertSame(2, $summary['collected']);
        $this->assertSame(1, $summary['pre_registered_donors']);
    }
}
