<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Scheduling mobile blood drives, and the boundary between a drive's capacity
 * and the host centre's own counter slots.
 */
class MobileEventTest extends TestCase
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
            'start_time' => '08:00',
            'end_time' => '16:00',
            'assigned_staff' => 'Maria Santos, Juan Cruz',
            'announcement' => 'Walk-ins welcome all day.',
        ], $overrides);
    }

    public function test_collection_staff_can_schedule_a_drive(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertCreated()
            ->assertJsonPath('name', 'UM Matina Bloodletting Drive')
            ->assertJsonPath('capacity', 60)
            ->assertJsonPath('registered_count', 0)
            ->assertJsonPath('status', 'Upcoming');

        $this->assertDatabaseHas('mobile_events', [
            'name' => 'UM Matina Bloodletting Drive',
            'facility_id' => $this->facility->id,
            'created_by' => $this->staff->id,
            'assigned_staff' => 'Maria Santos, Juan Cruz',
        ]);
    }

    public function test_the_hours_window_survives_the_round_trip_as_wall_clock_time(): void
    {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertCreated();

        // Not a full timestamp: the donor screen renders these verbatim.
        $this->assertSame('08:00', substr((string) $response->json('start_time'), 0, 5));
        $this->assertSame('16:00', substr((string) $response->json('end_time'), 0, 5));
    }

    public function test_a_facility_id_in_the_payload_is_ignored(): void
    {
        $other = Facility::factory()->approved()->create();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['facility_id' => $other->id]))
            ->assertCreated();

        $this->assertDatabaseHas('mobile_events', [
            'name' => 'UM Matina Bloodletting Drive',
            'facility_id' => $this->facility->id,
        ]);
        $this->assertDatabaseMissing('mobile_events', ['facility_id' => $other->id]);
    }

    public function test_a_department_without_drives_manage_is_refused(): void
    {
        $lab = User::factory()->bloodCenterStaff($this->facility, Department::Testing)->create();

        $this->actingAs($lab)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('mobile_events', 0);
    }

    /**
     * The facility.operational middleware refuses this before the service is
     * reached, so the 404 in MobileEventService::requireFacility() is defence in
     * depth rather than the gate. Asserted here so the route stays behind that
     * middleware — a drive with no facility_id could not be inserted at all.
     */
    public function test_an_account_with_no_facility_cannot_schedule_a_drive(): void
    {
        $unlinked = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();
        $unlinked->forceFill(['facility_id' => null])->save();

        $this->actingAs($unlinked)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_missing');

        $this->assertDatabaseCount('mobile_events', 0);
    }

    public function test_a_drive_cannot_be_scheduled_in_the_past(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload([
                'event_date' => now()->subDay()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_date');
    }

    public function test_the_end_time_must_follow_the_start_time(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload([
                'start_time' => '16:00',
                'end_time' => '08:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');
    }

    public function test_a_drive_needs_a_name(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_list_is_scoped_to_the_callers_own_facility(): void
    {
        MobileEvent::factory()->create(['facility_id' => $this->facility->id, 'name' => 'Ours']);
        MobileEvent::factory()->create([
            'facility_id' => Facility::factory()->approved()->create()->id,
            'name' => 'Theirs',
        ]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/drives')
            ->assertOk();

        $names = array_column($response->json('drives'), 'name');
        $this->assertSame(['Ours'], $names);
    }

    public function test_the_list_reports_live_registered_counts_and_headline_figures(): void
    {
        $drive = MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => now()->addWeek()->toDateString(),
            'max_capacity' => 10,
        ]);

        $this->registerDonorTo($drive);
        $this->registerDonorTo($drive);

        // A cancelled registration frees its place and must not be counted.
        $this->registerDonorTo($drive, ['status' => AppointmentStatus::Cancelled]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/drives')
            ->assertOk()
            ->assertJsonPath('upcoming_drives_count', 1)
            ->assertJsonPath('total_registered', 2);

        $this->assertSame(2, $response->json('drives.0.registered_count'));
        $this->assertSame('Upcoming', $response->json('drives.0.status'));
    }

    public function test_a_past_drive_reads_as_completed_and_is_not_counted_as_upcoming(): void
    {
        MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => now()->subWeek()->toDateString(),
        ]);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/drives')
            ->assertOk()
            ->assertJsonPath('upcoming_drives_count', 0)
            ->assertJsonPath('drives.0.status', 'Completed');
    }

    public function test_a_drive_at_capacity_reads_as_full(): void
    {
        $drive = MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => now()->addWeek()->toDateString(),
            'max_capacity' => 1,
        ]);

        $this->registerDonorTo($drive);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/drives')
            ->assertOk()
            ->assertJsonPath('drives.0.status', 'Full');
    }

    public function test_units_collected_this_month_counts_only_drawn_bags(): void
    {
        $donor = $this->donorWithProfile();

        foreach ([DonationStatus::Collected, DonationStatus::Tested, DonationStatus::Completed] as $status) {
            Donation::factory()->create([
                'donor_id' => $donor->donor_id,
                'facility_id' => $this->facility->id,
                'donation_date' => now(),
                'status' => $status,
            ]);
        }

        // Neither of these put blood in a bag.
        foreach ([DonationStatus::Registered, DonationStatus::Rejected] as $status) {
            Donation::factory()->create([
                'donor_id' => $donor->donor_id,
                'facility_id' => $this->facility->id,
                'donation_date' => now(),
                'status' => $status,
            ]);
        }

        // Drawn, but in a different month.
        Donation::factory()->create([
            'donor_id' => $donor->donor_id,
            'facility_id' => $this->facility->id,
            'donation_date' => now()->subMonthNoOverflow()->startOfMonth(),
            'status' => DonationStatus::Collected,
        ]);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/drives')
            ->assertOk()
            ->assertJsonPath('units_collected_month', 3);
    }

    /**
     * The bug this guards: a drive registration is stored against its host
     * facility at a nominal 09:00. Counter capacity must ignore it, or one
     * drive would close the centre's morning to walk-in donors.
     */
    public function test_drive_registrations_do_not_consume_walk_in_slots(): void
    {
        $this->openCounter();

        $date = now()->addWeek()->startOfDay();

        $drive = MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => $date->toDateString(),
            'max_capacity' => 50,
        ]);

        // Three drive registrations at the nominal time — more than the
        // counter's slot_capacity of 2.
        for ($i = 0; $i < 3; $i++) {
            $this->registerDonorTo($drive, [
                'appointment_datetime' => $date->copy()->setTime(9, 0),
            ]);
        }

        $nine = $this->nineAmSlotFor($date);

        $this->assertSame(2, $nine['available'], 'Drive registrations must not reduce walk-in availability.');
    }

    public function test_walk_in_bookings_still_consume_walk_in_slots(): void
    {
        $this->openCounter();

        $date = now()->addWeek()->startOfDay();

        DonationAppointment::factory()->create([
            'donor_id' => $this->donorWithProfile()->donor_id,
            'facility_id' => $this->facility->id,
            'event_id' => null,
            'appointment_datetime' => $date->copy()->setTime(9, 0),
        ]);

        $nine = $this->nineAmSlotFor($date);

        $this->assertSame(1, $nine['available']);
    }

    /**
     * The whole point of the feature: a drive the blood centre schedules is
     * one a donor can actually register for. Covers the join the two modules
     * meet at — GET /blood-drives is a separate read path over the same rows.
     */
    public function test_a_drive_the_centre_schedules_can_be_booked_by_a_donor(): void
    {
        $this->facility->forceFill(['is_accepting_donations' => true])->save();

        $created = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/drives', $this->payload())
            ->assertCreated()
            ->json();

        $donor = User::factory()->donor()->create();
        EligibilityScreening::factory()->create(['donor_id' => $donor->id]);

        $this->actingAs($donor)
            ->getJson('/api/blood-drives')
            ->assertOk()
            ->assertJsonPath('0.id', $created['id'])
            ->assertJsonPath('0.name', 'UM Matina Bloodletting Drive')
            ->assertJsonPath('0.total_slots', 60);

        $this->actingAs($donor)
            ->postJson('/api/donors/appointments', [
                'type' => 'mobile',
                'drive_id' => $created['id'],
                'time_slot' => '09:00',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'scheduled');

        // And the centre sees the registration against that drive.
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/drives')
            ->assertOk()
            ->assertJsonPath('total_registered', 1)
            ->assertJsonPath('drives.0.registered_count', 1);
    }

    private function openCounter(): void
    {
        $this->facility->forceFill([
            'is_accepting_donations' => true,
            'slot_capacity' => 2,
            'slot_interval_minutes' => 60,
            'slots_start_at' => '09:00',
            'slots_end_at' => '11:00',
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function nineAmSlotFor(Carbon $date): array
    {
        $donor = User::factory()->donor()->create();
        EligibilityScreening::factory()->create(['donor_id' => $donor->id]);

        $slots = $this->actingAs($donor)
            ->getJson('/api/time-slots?center_id='.$this->facility->id.'&date='.$date->toDateString())
            ->assertOk()
            ->json();

        return collect($slots)->firstWhere('time', '09:00');
    }

    private function donorWithProfile(): DonorProfile
    {
        $donor = User::factory()->donor()->create();

        return $donor->donorProfile ?? DonorProfile::factory()->create(['donor_id' => $donor->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function registerDonorTo(MobileEvent $drive, array $overrides = []): DonationAppointment
    {
        return DonationAppointment::factory()->create([
            'donor_id' => $this->donorWithProfile()->donor_id,
            'facility_id' => $drive->facility_id,
            'event_id' => $drive->id,
            'appointment_datetime' => $drive->event_date->copy()->setTime(9, 0),
            ...$overrides,
        ]);
    }
}
