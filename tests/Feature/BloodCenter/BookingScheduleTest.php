<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * When a blood centre takes bookings, kept by its Admin and its Medical
 * Receptionist, and what the Donor Portal offers from it.
 */
class BookingScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 21 October 2026.
        Carbon::setTestNow(Carbon::parse('2026-10-21 07:00', config('blood_center.timezone')));
        $this->centre = Facility::factory()->approved()->create([
            'is_accepting_donations' => true,
            'slots_start_at' => '08:00',
            'slots_end_at' => '11:00',
            'slot_interval_minutes' => 30,
            'slot_capacity' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function schedule(array $overrides = []): array
    {
        return [
            'open_days' => [0, 1, 2, 3, 4],
            'slots_start_at' => '08:00',
            'slots_end_at' => '15:00',
            'slot_interval_minutes' => 30,
            'slot_capacity' => 4,
            'is_accepting_donations' => true,
            'closed_dates' => [['date' => '2026-10-23', 'reason' => 'Staff training']],
            ...$overrides,
        ];
    }

    public function test_the_receptionist_and_the_admin_keep_it_and_others_may_not(): void
    {
        $receptionist = User::factory()->bloodCenterStaff($this->centre, StaffRole::MedicalReceptionist)->create();

        $this->actingAs($receptionist)
            ->putJson('/api/blood-center/booking-schedule', $this->schedule())
            ->assertOk()
            ->assertJsonPath('data.open_days', [0, 1, 2, 3, 4])
            ->assertJsonPath('data.slots_per_day', 15)
            ->assertJsonPath('data.donors_per_day', 60);

        $this->actingAs(User::factory()->bloodCenterAdmin($this->centre)->create())
            ->getJson('/api/blood-center/booking-schedule')
            ->assertOk()
            ->assertJsonPath('data.closed_dates.0.reason', 'Staff training');

        $this->actingAs(User::factory()->bloodCenterStaff($this->centre, StaffRole::Phlebotomist)->create())
            ->putJson('/api/blood-center/booking-schedule', $this->schedule())
            ->assertForbidden();
    }

    public function test_a_closed_day_offers_no_times_and_refuses_a_booking(): void
    {
        $this->centre->update(['open_days' => [0, 1, 2, 3, 4], 'closed_dates' => [['date' => '2026-10-23', 'reason' => 'Staff training']]]);
        $donor = User::factory()->donor()->create();
        $slots = fn (string $date): array => $this->actingAs($donor)
            ->getJson("/api/time-slots?center_id={$this->centre->id}&date={$date}")
            ->json() ?? [];

        $this->assertNotEmpty($slots('2026-10-22'));
        $this->assertSame([], $slots('2026-10-23'));
        $this->assertSame([], $slots('2026-10-24'));

        $this->assertSame('Staff training', $this->centre->fresh()->closedReasonOn(Carbon::parse('2026-10-23')));
        $this->assertSame('Not open on Saturdays', $this->centre->fresh()->closedReasonOn(Carbon::parse('2026-10-24')));
        $this->assertNull($this->centre->fresh()->closedReasonOn(Carbon::parse('2026-10-22')));
    }

    public function test_the_schedule_is_checked(): void
    {
        $receptionist = User::factory()->bloodCenterStaff($this->centre, StaffRole::MedicalReceptionist)->create();

        $this->actingAs($receptionist)
            ->putJson('/api/blood-center/booking-schedule', $this->schedule([
                'open_days' => [],
                'slots_end_at' => '07:00',
                'slot_interval_minutes' => 7,
                'closed_dates' => [['date' => '2026-10-01']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['open_days', 'slots_end_at', 'slot_interval_minutes', 'closed_dates.0.date']);
    }
}
