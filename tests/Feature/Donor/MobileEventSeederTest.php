<?php

namespace Tests\Feature\Donor;

use App\Models\MobileEvent;
use App\Models\User;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\MobileEventSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MobileEventSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function seedDrives(): void
    {
        $this->seed(FacilitySeeder::class);
        $this->seed(MobileEventSeeder::class);
    }

    public function test_it_seeds_drives_against_the_seeded_facilities(): void
    {
        $this->seedDrives();

        $this->assertSame(6, MobileEvent::count());
        $this->assertSame(0, MobileEvent::whereNull('facility_id')->count());
    }

    public function test_every_seeded_drive_is_visible_in_the_booking_catalogue(): void
    {
        $this->seedDrives();

        // upcomingDrives() filters on event_date >= today, so a seeder using
        // fixed dates would return fewer rows than it inserted. This is the
        // regression guard for that.
        $this->actingAs(User::factory()->donor()->create())
            ->getJson('/api/blood-drives')
            ->assertOk()
            ->assertJsonCount(MobileEvent::count());
    }

    public function test_it_covers_both_the_open_and_upcoming_statuses(): void
    {
        $this->seedDrives();

        $statuses = collect(
            $this->actingAs(User::factory()->donor()->create())
                ->getJson('/api/blood-drives')
                ->assertOk()
                ->json()
        )->pluck('status');

        $this->assertContains('Open', $statuses, 'Expected a drive dated today.');
        $this->assertContains('Upcoming', $statuses, 'Expected at least one future drive.');
    }

    public function test_the_drives_stay_visible_as_time_passes(): void
    {
        $this->seedDrives();

        // Seeded relative to "today", so re-seeding a month later must still
        // produce a full catalogue rather than a shrinking one.
        Carbon::setTestNow(now()->addMonths(1));
        $this->seed(MobileEventSeeder::class);

        $this->actingAs(User::factory()->donor()->create())
            ->getJson('/api/blood-drives')
            ->assertOk()
            ->assertJsonCount(6);

        Carbon::setTestNow();
    }

    public function test_re_running_the_seeder_does_not_duplicate_drives(): void
    {
        $this->seedDrives();
        $this->seed(MobileEventSeeder::class);

        $this->assertSame(6, MobileEvent::count());
    }

    public function test_it_skips_cleanly_when_no_facilities_exist(): void
    {
        $this->seed(MobileEventSeeder::class);

        $this->assertSame(0, MobileEvent::count());
    }
}
