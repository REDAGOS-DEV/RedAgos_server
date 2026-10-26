<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * A donor brought up at the counter from their record (project owner,
 * 2026-10-24): Donor Management's Start donation and Donations' Continue,
 * now that the counter searches for nobody itself.
 *
 * What is locked in: it answers as the QR scan does (today's booking, the
 * visit under way, the deferral notice), checks nobody in, reaches any
 * RedAgos donor, and is the counter's to ask.
 */
class CounterArrivalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $receptionist;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
        $this->donor = User::factory()->donor()->create();
    }

    private function arrival(User $donor)
    {
        return $this->actingAs($this->receptionist)->getJson("/api/blood-center/collection/arrival/{$donor->uuid}");
    }

    public function test_it_brings_up_todays_booking_without_checking_the_donor_in(): void
    {
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => AppointmentStatus::Scheduled,
            'appointment_datetime' => now(),
        ]);

        $this->arrival($this->donor)
            ->assertOk()
            ->assertJsonPath('data.donor.uuid', $this->donor->uuid)
            ->assertJsonPath('data.appointment.id', $appointment->id)
            ->assertJsonPath('data.open_donation', null)
            ->assertJsonStructure(['data' => ['health_questionnaire', 'prior_deferral']]);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);

        $log = AuditLog::query()->where('action', 'donor.looked_up')->sole();
        $this->assertSame('record', $log->context['identifier_type']);
    }

    public function test_it_resumes_the_visit_already_under_way(): void
    {
        $visit = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Registered,
            'donation_date' => now(),
        ]);

        $this->arrival($this->donor)
            ->assertOk()
            ->assertJsonPath('data.open_donation.id', $visit->id);
    }

    public function test_another_centres_donor_is_brought_up_too(): void
    {
        // Donor Management lists them all (project owner, 2026-10-25).
        $theirs = User::factory()->donor()->create();
        Donation::factory()->create([
            'donor_id' => $theirs->id,
            'facility_id' => Facility::factory()->approved()->create()->id,
            'status' => DonationStatus::Completed,
        ]);

        $this->arrival($theirs)->assertOk()->assertJsonPath('data.donor.uuid', $theirs->uuid);

        $this->assertSame('cross_facility', AuditLog::query()->where('action', 'donor.looked_up')->sole()->context['scope']);
    }

    public function test_only_the_counter_may_ask(): void
    {
        $billing = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create();

        $this->actingAs($billing)
            ->getJson("/api/blood-center/collection/arrival/{$this->donor->uuid}")
            ->assertForbidden();
    }
}
