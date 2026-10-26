<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RecordsCounterVitals;
use Tests\TestCase;

/**
 * The onsite DHQ is optional (project owner, 2026-10-17): a donor may answer
 * the printed form instead, and the screening says so.
 *
 * What is locked in: a donor with no questionnaire in RedAgos can be accepted
 * (the server never required one), and a paper DHQ is recorded on the
 * screening rather than left unsaid.
 */
class PaperQuestionnaireTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RecordsCounterVitals;

    private Facility $facility;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
    }

    private function walkIn(): Donation
    {
        return Donation::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => $this->facility->id,
            'donation_date' => now(),
            'status' => DonationStatus::Registered,
        ]);
    }

    public function test_a_walk_in_who_answered_the_paper_dhq_is_accepted_and_it_is_recorded(): void
    {
        $visit = $this->walkIn();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$visit->id}/screening", $this->withVitals(['outcome' => 'accepted', 'dhq_on_paper' => true]))
            ->assertCreated();

        $this->assertDatabaseHas('donation_screenings', ['donation_id' => $visit->id, 'dhq_on_paper' => true]);
        $this->assertSame(DonationStatus::Screening, $visit->fresh()->status);
    }

    public function test_a_screening_without_the_flag_records_no_paper_dhq(): void
    {
        $visit = $this->walkIn();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$visit->id}/screening", $this->withVitals(['outcome' => 'accepted']))
            ->assertCreated();

        $this->assertDatabaseHas('donation_screenings', ['donation_id' => $visit->id, 'dhq_on_paper' => false]);
    }
}
