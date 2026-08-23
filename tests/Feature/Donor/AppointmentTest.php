<?php

namespace Tests\Feature\Donor;

use App\Enums\RoleName;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    private Facility $center;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = User::factory()->donor()->create();
        $this->center = Facility::factory()->create();
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'walkin',
            'center_id' => $this->center->id,
            'date' => now()->addWeek()->toDateString(),
            'time_slot' => '09:00',
        ], $overrides);
    }

    public function test_an_eligible_verified_donor_can_book(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('appointment_type', 'walk_in')
            ->assertJsonPath('facility_name', $this->center->name);

        $this->assertSame(1, DonationAppointment::count());
    }

    public function test_an_unverified_donor_cannot_book(): void
    {
        $donor = User::factory()->unverified()->donor()->create();
        EligibilityScreening::factory()->create(['donor_id' => $donor->id]);

        $this->actingAs($donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_booking_without_a_screening_is_refused(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'screening_required');
    }

    public function test_booking_with_an_expired_screening_is_refused(): void
    {
        $donor = User::factory()->donor()->create();
        EligibilityScreening::factory()->expired()->create(['donor_id' => $donor->id]);

        $this->actingAs($donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'screening_expired');
    }

    public function test_booking_after_a_deferral_is_refused(): void
    {
        $donor = User::factory()->donor()->create();
        EligibilityScreening::factory()->deferred()->create(['donor_id' => $donor->id]);

        $this->actingAs($donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'screening_required');
    }

    public function test_booking_inside_the_donation_interval_is_refused(): void
    {
        Donation::factory()->completedAt(now()->subDays(10)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload([
                'date' => now()->addDays(2)->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'below_min_interval')
            ->assertJsonPath('next_eligible_date', now()->subDays(10)->addDays(56)->toDateString());
    }

    public function test_booking_after_the_donation_interval_elapses_is_allowed(): void
    {
        Donation::factory()->completedAt(now()->subDays(10)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload([
                'date' => now()->addDays(50)->toDateString(),
            ]))
            ->assertCreated();
    }

    public function test_a_full_slot_is_refused(): void
    {
        $center = Facility::factory()->singleSlot()->create();
        $other = User::factory()->donor()->create();
        DonationAppointment::factory()->create([
            'donor_id' => $other->id,
            'facility_id' => $center->id,
            'appointment_datetime' => now()->addWeek()->setTime(8, 0),
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload([
                'center_id' => $center->id,
                'date' => now()->addWeek()->toDateString(),
                'time_slot' => '08:00',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'slot_unavailable');
    }

    public function test_a_second_active_appointment_is_refused(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload(['time_slot' => '10:00']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_appointment');
    }

    public function test_booking_again_after_cancelling_is_allowed(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/appointments/'.$response->json('id'))
            ->assertOk();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload(['time_slot' => '10:00']))
            ->assertCreated();
    }

    public function test_booking_in_the_past_is_refused(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload([
                'date' => now()->subDay()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_booking_beyond_the_horizon_is_refused(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload([
                'date' => now()->addDays(120)->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_booking_at_a_closed_centre_is_refused(): void
    {
        $closed = Facility::factory()->notAcceptingDonations()->create();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload(['center_id' => $closed->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_id');
    }

    public function test_a_donor_can_register_for_a_mobile_drive(): void
    {
        $drive = MobileEvent::factory()->create(['facility_id' => $this->center->id]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', [
                'type' => 'mobile',
                'drive_id' => $drive->id,
                'time_slot' => '09:00',
            ])
            ->assertCreated()
            ->assertJsonPath('appointment_type', 'mobile')
            ->assertJsonPath('drive_name', $drive->name);
    }

    public function test_a_full_drive_is_refused(): void
    {
        $drive = MobileEvent::factory()->create([
            'facility_id' => $this->center->id,
            'max_capacity' => 1,
        ]);
        $other = User::factory()->donor()->create();
        DonationAppointment::factory()->create([
            'donor_id' => $other->id,
            'facility_id' => $this->center->id,
            'event_id' => $drive->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', [
                'type' => 'mobile',
                'drive_id' => $drive->id,
                'time_slot' => '09:00',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'drive_full');
    }

    public function test_a_donor_can_cancel_their_own_appointment(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/appointments/'.$response->json('id'))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', DonationAppointment::find($response->json('id'))->status);
        $this->assertSame(1, DonationAppointment::count());
    }

    public function test_a_donor_cannot_cancel_another_donors_appointment(): void
    {
        $other = User::factory()->donor()->create();
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $other->id,
            'facility_id' => $this->center->id,
        ]);

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/appointments/'.$appointment->id)
            ->assertForbidden();

        $this->assertSame('scheduled', $appointment->fresh()->status);
    }

    public function test_a_donor_cannot_reschedule_another_donors_appointment(): void
    {
        $other = User::factory()->donor()->create();
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $other->id,
            'facility_id' => $this->center->id,
        ]);

        $this->actingAs($this->donor)
            ->patchJson('/api/donors/appointments/'.$appointment->id, $this->payload(['time_slot' => '11:00']))
            ->assertForbidden();
    }

    public function test_cancelling_inside_the_twenty_four_hour_window_is_refused(): void
    {
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->center->id,
            'appointment_datetime' => now()->addHours(6),
        ]);

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/appointments/'.$appointment->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'cancellation_window_passed');
    }

    public function test_an_already_cancelled_appointment_cannot_be_cancelled_again(): void
    {
        $appointment = DonationAppointment::factory()->cancelled()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->center->id,
        ]);

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/appointments/'.$appointment->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'appointment_not_active');
    }

    public function test_a_donor_can_reschedule_their_own_appointment(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/appointments', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->patchJson('/api/donors/appointments/'.$response->json('id'), $this->payload([
                'date' => now()->addDays(10)->toDateString(),
                'time_slot' => '11:00',
            ]))
            ->assertOk()
            ->assertJsonPath('time', '11:00')
            ->assertJsonPath('date', now()->addDays(10)->toDateString());

        $this->assertSame(1, DonationAppointment::count());
    }

    public function test_rescheduling_into_the_donation_interval_is_refused(): void
    {
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->center->id,
            'appointment_datetime' => now()->addDays(60),
        ]);
        Donation::factory()->completedAt(now()->subDays(5)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->patchJson('/api/donors/appointments/'.$appointment->id, $this->payload([
                'date' => now()->addDays(3)->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'below_min_interval');
    }

    public function test_a_donor_only_sees_their_own_appointments(): void
    {
        $other = User::factory()->donor()->create();
        DonationAppointment::factory()->create([
            'donor_id' => $other->id,
            'facility_id' => $this->center->id,
        ]);
        $this->actingAs($this->donor)->postJson('/api/donors/appointments', $this->payload())->assertCreated();

        $this->actingAs($this->donor)
            ->getJson('/api/donors/appointments')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_time_slots_reflect_existing_bookings(): void
    {
        $date = now()->addWeek()->toDateString();
        DonationAppointment::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => $this->center->id,
            'appointment_datetime' => $date.' 09:00:00',
        ]);

        $response = $this->actingAs($this->donor)
            ->getJson("/api/time-slots?center_id={$this->center->id}&date={$date}")
            ->assertOk();

        $slot = collect($response->json())->firstWhere('time', '09:00');

        $this->assertSame(4, $slot['total']);
        $this->assertSame(3, $slot['available']);
    }

    public function test_time_slots_require_a_centre_and_date(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/time-slots')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['center_id', 'date']);
    }

    public function test_blood_centres_lists_only_open_facilities(): void
    {
        Facility::factory()->notAcceptingDonations()->create();

        $this->actingAs($this->donor)
            ->getJson('/api/blood-centers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->center->id);
    }

    public function test_blood_drives_lists_upcoming_drives_with_capacity(): void
    {
        MobileEvent::factory()->past()->create(['facility_id' => $this->center->id]);
        $upcoming = MobileEvent::factory()->create([
            'facility_id' => $this->center->id,
            'max_capacity' => 60,
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/blood-drives')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $upcoming->id)
            ->assertJsonPath('0.registered', 0)
            ->assertJsonPath('0.total_slots', 60);
    }

    public function test_appointment_endpoints_reject_a_non_donor(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/donors/appointments')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/donors/appointments', $this->payload())->assertForbidden();
    }

    public function test_appointment_endpoints_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/donors/appointments')->assertUnauthorized();
        $this->postJson('/api/donors/appointments', $this->payload())->assertUnauthorized();
        $this->getJson('/api/blood-centers')->assertUnauthorized();
        $this->getJson('/api/time-slots')->assertUnauthorized();
    }
}
