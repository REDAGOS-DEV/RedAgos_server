<?php

namespace Tests\Feature\Donor;

use App\Models\MobileEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The landing page's upcoming drives (project owner, 2026-11-19): only drives
 * a centre has created and published, none once over, public details only.
 */
class PublicDrivesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 19 Nov 2026, 10:00 in Manila.
        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Asia/Manila'));
    }

    public function test_nothing_is_listed_when_no_drive_was_created(): void
    {
        $this->getJson('/api/public/drives')->assertOk()->assertExactJson([]);
    }

    public function test_a_guest_sees_published_upcoming_drives_soonest_first(): void
    {
        $later = MobileEvent::factory()->create(['name' => 'City Hall Drive', 'event_date' => '2026-12-05', 'start_time' => '07:00', 'end_time' => '12:00']);
        $sooner = MobileEvent::factory()->create(['name' => 'Mall Drive', 'event_date' => '2026-11-21', 'start_time' => '09:00', 'end_time' => '17:00']);

        $this->getJson('/api/public/drives')
            ->assertOk()
            ->assertExactJson([
                ['id' => $sooner->id, 'name' => 'Mall Drive', 'location' => $sooner->location, 'date' => '2026-11-21', 'start_time' => '09:00', 'end_time' => '17:00'],
                ['id' => $later->id, 'name' => 'City Hall Drive', 'location' => $later->location, 'date' => '2026-12-05', 'start_time' => '07:00', 'end_time' => '12:00'],
            ]);
    }

    public function test_past_draft_cancelled_and_closed_drives_are_left_out(): void
    {
        MobileEvent::factory()->create(['event_date' => '2026-11-18']);
        MobileEvent::factory()->draft()->create(['event_date' => '2026-11-25']);
        MobileEvent::factory()->cancelled()->create(['event_date' => '2026-11-25']);
        MobileEvent::factory()->registrationClosed()->create(['event_date' => '2026-11-25']);

        $this->getJson('/api/public/drives')->assertOk()->assertExactJson([]);
    }

    public function test_todays_drive_leaves_the_list_at_its_end_time(): void
    {
        $drive = MobileEvent::factory()->create(['event_date' => '2026-11-19', 'start_time' => '08:00', 'end_time' => '12:00']);

        $this->getJson('/api/public/drives')->assertOk()->assertJsonPath('0.id', $drive->id);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00', 'Asia/Manila'));

        $this->getJson('/api/public/drives')->assertOk()->assertExactJson([]);
    }

    public function test_todays_drive_with_no_end_time_stays_through_the_day(): void
    {
        $drive = MobileEvent::factory()->create(['event_date' => '2026-11-19', 'start_time' => null, 'end_time' => null]);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 23:30', 'Asia/Manila'));

        $this->getJson('/api/public/drives')->assertOk()->assertJsonPath('0.id', $drive->id);
    }
}
