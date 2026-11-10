<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\StaffRole;
use App\Models\BloodCollection;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\RecordsCounterVitals;
use Tests\TestCase;

/**
 * Who is on Donor Management (project owner, 2026-11-01).
 *
 * A Donor Portal account is a prospective donor, on no centre's list. A
 * booking puts the person on that centre's Appointments or drive roster, and
 * still on no list. Checking in, at the centre or a drive, makes them that
 * centre's donor; the list itself is the same at every centre (2026-10-25).
 * Only a collection record makes a visit a donation: someone screened and
 * deferred came in, but has not donated.
 */
class DonorDirectoryMembershipTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RecordsCounterVitals;

    private Facility $centre;

    private Facility $other;

    private User $receptionist;

    private User $physician;

    private User $phlebotomist;

    private User $otherReceptionist;

    private User $otherPhysician;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->centre = Facility::factory()->approved()->create();
        $this->other = Facility::factory()->approved()->create();

        $this->receptionist = User::factory()->bloodCenterStaff($this->centre, StaffRole::MedicalReceptionist)->create();
        $this->physician = User::factory()->bloodCenterStaff($this->centre, StaffRole::ScreeningPhysician)->create();
        $this->phlebotomist = User::factory()->bloodCenterStaff($this->centre, StaffRole::Phlebotomist)->create();
        $this->otherReceptionist = User::factory()->bloodCenterStaff($this->other, StaffRole::MedicalReceptionist)->create();
        $this->otherPhysician = User::factory()->bloodCenterStaff($this->other, StaffRole::ScreeningPhysician)->create();
    }

    // --- Portal registration ----------------------------------------------------

    public function test_a_portal_registration_alone_is_on_no_centres_list(): void
    {
        BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);

        $this->postJson('/api/donors/register', [
            'first_name' => 'Juan',
            'last_name' => 'Portalonly',
            'email' => 'juan.portal@example.com',
            'phone' => '09171234567',
            'blood_type' => 'O+',
            'gender' => 'male',
            'birth_date' => '1995-05-20',
            'address' => '123 Rizal Street, Davao City',
            'valid_id_type' => 'philsys',
            'valid_id_number' => '1234567890123456',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms_accepted' => true,
        ])->assertCreated();

        $donor = User::query()->where('email', 'juan.portal@example.com')->firstOrFail();

        $this->assertArrayNotHasKey($donor->uuid, $this->listed($this->receptionist));
        $this->assertArrayNotHasKey($donor->uuid, $this->listed($this->otherReceptionist));
        $this->assertStringNotContainsString('Portalonly', $this->exported($this->receptionist));
        $this->assertSame(0, $this->dashboard($this->receptionist)['pool']['total']);

        // Still found by what they hand over in person, so the desk can start
        // their first visit without registering them twice.
        $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/donors/lookup?type=email&value=juan.portal@example.com')
            ->assertOk()
            ->assertJsonPath('uuid', $donor->uuid)
            ->assertJsonPath('scope', 'cross_facility');

        $this->actingAs($this->receptionist)
            ->getJson("/api/blood-center/collection/arrival/{$donor->uuid}")
            ->assertOk()
            ->assertJsonPath('data.donor.uuid', $donor->uuid);
    }

    public function test_a_status_or_note_kept_on_someone_who_never_came_does_not_list_them(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$donor->uuid}", ['notes' => 'Called about the October drive.'])
            ->assertOk();

        $this->assertDatabaseHas('facility_donors', ['facility_id' => $this->centre->id, 'donor_id' => $donor->id, 'registered_here' => false]);
        $this->assertArrayNotHasKey($donor->uuid, $this->listed($this->receptionist));
    }

    // --- Booked, not checked in -------------------------------------------------

    public function test_a_booking_without_check_in_is_on_the_roster_only(): void
    {
        $donor = User::factory()->donor()->create(['last_name' => 'Bookedonly']);
        $booking = $this->bookToday($donor, $this->centre);

        $roster = collect($this->actingAs($this->receptionist)->getJson('/api/blood-center/appointments')->assertOk()->json('data'))
            ->pluck('donor.uuid');
        $this->assertContains($donor->uuid, $roster);

        $this->assertArrayNotHasKey($donor->uuid, $this->listed($this->receptionist));
        $this->assertArrayNotHasKey($donor->uuid, $this->listed($this->otherReceptionist));
        $this->assertStringNotContainsString('Bookedonly', $this->exported($this->receptionist));
        $this->assertSame(0, $this->dashboard($this->receptionist)['pool']['total']);
        $this->assertSame(0, $this->actingAs($this->receptionist)->getJson('/api/blood-center/donors/eligibility-reminders')->assertOk()->json('recipients'));

        // Not this centre's donor, so nothing that is a centre's own act on one.
        $this->actingAs($this->receptionist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/email", ['template' => 'eligibility_reminder'])
            ->assertForbidden()
            ->assertJsonPath('code', 'donor_not_at_facility');

        // A no-show never came either.
        $this->actingAs($this->receptionist)->postJson("/api/blood-center/appointments/{$booking->id}/no-show")->assertOk();
        $this->assertArrayNotHasKey($donor->uuid, $this->listed($this->receptionist));
    }

    // --- Checked in ---------------------------------------------------------------

    public function test_a_checked_in_participant_who_is_deferred_is_listed_but_has_not_donated(): void
    {
        $donor = User::factory()->donor()->create();
        $booking = $this->bookToday($donor, $this->centre);

        $this->actingAs($this->receptionist)->postJson("/api/blood-center/appointments/{$booking->id}/check-in")->assertOk();

        $row = $this->listed($this->receptionist)[$donor->uuid] ?? null;
        $this->assertNotNull($row, 'Checking in makes the person this centre\'s donor.');
        $this->assertSame('own_facility', $row['scope']);

        $visit = $this->openVisit($donor, $booking);

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$visit}/screening", $this->withVitals([
                'outcome' => 'temporarily_deferred',
                'other_reason' => 'Fever this week',
            ]))
            ->assertCreated();

        $row = $this->listed($this->receptionist)[$donor->uuid];
        $this->assertSame(0, $row['total_donations']);
        $this->assertSame(0, $row['donations_here']);
        $this->assertNull($row['last_donation_at']);
        $this->assertSame('deferred', $row['eligibility']);

        $today = $this->dashboard($this->receptionist)['today']['donations'];
        $this->assertSame(0, $today['collected']);
        $this->assertSame(1, $today['deferred']);

        // A thank-you needs a donation to thank them for.
        $this->actingAs($this->receptionist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/email", ['template' => 'thank_you'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'no_donation_here');

        // The visit closed the booking, and the roster reads it as deferred.
        $this->assertSame(AppointmentStatus::Completed, $booking->fresh()->status);
        $roster = collect($this->actingAs($this->receptionist)->getJson('/api/blood-center/appointments')->json('data'))->keyBy('id');
        $this->assertSame('Deferred', $roster[$booking->id]['status_label']);
    }

    public function test_a_donor_checked_in_by_qr_and_drawn_counts_one_donation(): void
    {
        $donor = User::factory()->donor()->create(['last_name' => 'Drawnhere']);
        $booking = $this->bookToday($donor, $this->centre);
        $raw = Str::random(40);
        DonorQrToken::factory()->create([
            'donor_id' => $donor->id,
            'token_hash' => hash('sha256', $raw),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('data.appointment.status', 'confirmed');

        $this->assertSame(0, $this->listed($this->receptionist)[$donor->uuid]['total_donations']);

        $visit = $this->openVisit($donor, $booking);
        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$visit}/screening", $this->withVitals(['outcome' => 'accepted']))
            ->assertCreated();
        $this->actingAs($this->phlebotomist)
            ->postJson("/api/blood-center/donations/{$visit}/collection", [
                'volume_ml' => 450,
                'blood_bag_type' => 'double',
                'donation_barcode' => 'SEG-7001',
                'started_at' => now()->subMinutes(15)->toISOString(),
                'ended_at' => now()->subMinutes(5)->toISOString(),
            ])
            ->assertCreated();

        $row = $this->listed($this->receptionist)[$donor->uuid];
        $this->assertSame(1, $row['total_donations']);
        $this->assertSame(1, $row['donations_here']);
        $this->assertNotNull($row['last_donation_at']);

        $this->assertStringContainsString('Drawnhere', $this->exported($this->receptionist));
        $this->assertSame(1, $this->dashboard($this->receptionist)['pool']['total']);
        $this->assertSame(1, $this->dashboard($this->receptionist)['today']['donations']['collected']);
    }

    public function test_only_a_collection_record_makes_a_visit_a_donation(): void
    {
        $donor = User::factory()->donor()->create();

        // A status that says completed, with no bag drawn behind it, is not a
        // donation; a bag drawn and later rejected still is.
        $unbacked = Donation::factory()->create(['donor_id' => $donor->id, 'facility_id' => $this->centre->id, 'status' => 'completed']);
        BloodCollection::query()->where('donation_id', $unbacked->id)->delete();
        Donation::factory()->create(['donor_id' => $donor->id, 'facility_id' => $this->centre->id, 'status' => 'rejected'])
            ->collection()->create(['collected_by' => $this->phlebotomist->id, 'collection_datetime' => now()->subMonths(5)]);

        $this->assertSame(1, $this->listed($this->receptionist)[$donor->uuid]['total_donations']);
    }

    // --- Across centres -------------------------------------------------------------

    public function test_another_centre_sees_the_donor_on_the_shared_list_but_not_its_records(): void
    {
        $donor = User::factory()->donor()->create(['last_name' => 'Givesatcentre']);
        $visit = Donation::factory()->create(['donor_id' => $donor->id, 'facility_id' => $this->centre->id, 'status' => 'completed']);

        // The same list everywhere, marked as the other centre's donor.
        $row = $this->listed($this->otherReceptionist)[$donor->uuid] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('cross_facility', $row['scope']);
        $this->assertSame(1, $row['total_donations']);
        $this->assertSame(0, $row['donations_here']);

        // Its records, export, reminders, emails and pool stay with the centre.
        $this->actingAs($this->otherPhysician)
            ->getJson("/api/blood-center/donors/{$donor->uuid}/history")
            ->assertOk()
            ->assertJsonPath('scope', 'cross_facility')
            ->assertJsonCount(0, 'donations');
        $this->assertStringNotContainsString('Givesatcentre', $this->exported($this->otherReceptionist));
        $this->assertSame(0, $this->dashboard($this->otherReceptionist)['pool']['total']);
        $this->actingAs($this->otherReceptionist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/email", ['template' => 'thank_you'])
            ->assertForbidden();

        // A booking at the other centre does not make them its donor either.
        $this->bookToday($donor, $this->other, AppointmentStatus::Scheduled, $visit->donation_date->addDays(60));
        $this->assertSame('cross_facility', $this->listed($this->otherReceptionist)[$donor->uuid]['scope']);

        // Booked at the other centre only, nobody lists them anywhere.
        $bookedThere = User::factory()->donor()->create();
        $this->bookToday($bookedThere, $this->other);
        $this->assertArrayNotHasKey($bookedThere->uuid, $this->listed($this->receptionist));
        $this->assertArrayNotHasKey($bookedThere->uuid, $this->listed($this->otherReceptionist));
    }

    // --- Paper records -------------------------------------------------------------------

    public function test_a_drive_paper_record_finds_the_people_booked_here_by_name(): void
    {
        $booked = User::factory()->donor()->create(['first_name' => 'Zenaida', 'last_name' => 'Quiambao']);
        $this->bookToday($booked, $this->centre);
        $elsewhere = User::factory()->donor()->create(['first_name' => 'Zenaida', 'last_name' => 'Quiambaoelse']);
        $this->bookToday($elsewhere, $this->other);

        $found = collect($this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'Quiambao'])
            ->assertOk()
            ->json('data'))->pluck('uuid');

        $this->assertContains($booked->uuid, $found);
        $this->assertNotContains($elsewhere->uuid, $found);
    }

    // --- Helpers -------------------------------------------------------------------------

    /**
     * Donor Management's list as this staff member sees it, keyed by uuid.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listed(User $staff): array
    {
        return collect($this->actingAs($staff)->getJson('/api/blood-center/donors?per_page=100')->assertOk()->json('data'))
            ->keyBy('uuid')
            ->all();
    }

    private function exported(User $staff): string
    {
        return (string) $this->actingAs($staff)->get('/api/blood-center/donors/export')->assertOk()->streamedContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(User $staff): array
    {
        return $this->actingAs($staff)->getJson('/api/blood-center/collection/dashboard')->assertOk()->json();
    }

    private function bookToday(User $donor, Facility $at, AppointmentStatus $status = AppointmentStatus::Scheduled, mixed $when = null): DonationAppointment
    {
        return DonationAppointment::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $at->id,
            'appointment_datetime' => $when ?? now(),
            'status' => $status,
        ]);
    }

    private function openVisit(User $donor, ?DonationAppointment $booking = null): int
    {
        return $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', array_filter([
                'donor_uuid' => $donor->uuid,
                'appointment_id' => $booking?->id,
            ]))
            ->assertCreated()
            ->json('data.id');
    }
}
