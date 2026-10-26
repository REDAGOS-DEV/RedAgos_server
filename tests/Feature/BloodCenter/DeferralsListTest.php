<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\PaperDonationSheet;
use App\Models\User;
use App\Repository\DonorDeferralRepository;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The Deferrals page lists the centre's screening deferrals beside the ones
 * recorded there (project owner, 2026-10-25).
 *
 * What is locked in: a counter or paper deferral at this centre is listed,
 * by its visit's code and source; another centre's and an accepted screening
 * are not; in force as the donor's standing reads it; found by its DON- code
 * or deferral code; and lifted as one recorded here is, freeing the donor
 * while the screening keeps its outcome.
 */
class DeferralsListTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
    }

    /**
     * @param  array<string, mixed>  $screening
     */
    private function screened(?User $donor = null, array $screening = [], ?Facility $at = null, ?int $sheetId = null, string $state = 'deferred'): DonationScreening
    {
        $at ??= $this->facility;

        $donation = Donation::factory()->create([
            'donor_id' => ($donor ?? User::factory()->donor()->create())->id,
            'facility_id' => $at->id,
            'status' => DonationStatus::Rejected,
            'paper_sheet_id' => $sheetId,
            'donation_date' => $screening['screened_at'] ?? now(),
        ]);

        $factory = DonationScreening::factory();
        $factory = $state === 'accepted' ? $factory : $factory->{$state}();

        return $factory->create([
            'donation_id' => $donation->id,
            'facility_id' => $at->id,
            'deferral_code' => $state === 'accepted' ? null : 'R1',
            ...$screening,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $query = ''): array
    {
        return $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals'.($query ? "?{$query}" : ''))
            ->assertOk()
            ->json('data');
    }

    public function test_a_counter_deferral_is_listed_by_its_visit_and_can_be_lifted_here(): void
    {
        $screening = $this->screened(screening: ['deferred_until' => OperationalDay::today()->addDays(10)->toDateString()]);
        $this->screened(state: 'accepted');
        $this->screened(at: Facility::factory()->approved()->create());

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('counter', $rows[0]['source']);
        $this->assertSame('DON-'.str_pad((string) $screening->donation_id, 6, '0', STR_PAD_LEFT), $rows[0]['code']);
        $this->assertSame("screening-{$screening->id}", $rows[0]['key']);
        $this->assertSame('R1', $rows[0]['reason_code']);
        $this->assertSame('temporary', $rows[0]['kind']);
        $this->assertSame('active', $rows[0]['status']);
        $this->assertTrue($rows[0]['can']['lift']);
    }

    public function test_a_drive_papers_deferral_says_so(): void
    {
        $sheet = PaperDonationSheet::create([
            'facility_id' => $this->facility->id,
            'drive_name' => 'Barangay drive',
            'location' => 'Covered court',
            'held_on' => OperationalDay::todayAsDate(),
            'starts_at' => '08:00',
            'screened_by_name' => 'Dr. Reyes',
            'phlebotomist_name' => 'Nurse Cruz',
            'created_by' => $this->physician->id,
        ]);
        $this->screened(sheetId: $sheet->id);

        $this->assertSame('paper', $this->rows()[0]['source']);
    }

    public function test_it_is_in_force_as_the_donors_standing_reads_it(): void
    {
        $donor = User::factory()->donor()->create();

        $ended = $this->screened(screening: ['deferred_until' => OperationalDay::today()->subDay()->toDateString(), 'screened_at' => now()->subDays(20)]);
        $permanent = $this->screened(state: 'permanentlyDeferred', screening: ['screened_at' => now()->subDays(30)]);
        // No Until date: in force until the donor is screened again.
        $superseded = $this->screened($donor, ['screened_at' => now()->subDays(5)]);
        $this->screened($donor, ['screened_at' => now()->subDay()], state: 'accepted');

        $status = collect($this->rows())->pluck('status', 'id');

        $this->assertSame('expired', $status[$ended->id]);
        $this->assertSame('active', $status[$permanent->id]);
        $this->assertSame('expired', $status[$superseded->id]);

        $this->assertSame([$permanent->id], collect($this->rows('status=active'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$ended->id, $superseded->id], collect($this->rows('status=expired'))->pluck('id')->all());
        $this->assertSame([], $this->rows('status=lifted'));
    }

    public function test_lifting_it_frees_the_donor_and_keeps_the_visits_outcome(): void
    {
        $donor = User::factory()->donor()->create();
        $screening = $this->screened($donor, ['deferred_until' => OperationalDay::today()->addDays(20)->toDateString()]);

        $this->actingAs($donor)->getJson('/api/donors/deferral')->assertJsonPath('deferred', true);

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$screening->id}/lift", ['source' => 'screening'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$screening->id}/lift", ['source' => 'screening', 'reason' => 'Haemoglobin rechecked in range'])
            ->assertOk()
            ->assertJsonPath('status', 'lifted')
            ->assertJsonPath('lift_reason', 'Haemoglobin rechecked in range')
            ->assertJsonPath('can.lift', false);

        $this->actingAs($donor)->getJson('/api/donors/deferral')->assertJsonPath('deferred', false);
        $this->assertSame(ScreeningOutcome::TemporarilyDeferred, $screening->fresh()->outcome);
        $this->assertSame([$screening->id], collect($this->rows('status=lifted'))->pluck('id')->all());

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$screening->id}/lift", ['source' => 'screening', 'reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'deferral_already_lifted');
    }

    public function test_a_lifted_permanent_one_no_longer_stands(): void
    {
        $donor = User::factory()->donor()->create();
        $screening = $this->screened($donor, state: 'permanentlyDeferred');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$screening->id}/lift", ['source' => 'screening', 'reason' => 'Recorded against the wrong donor'])
            ->assertOk();

        $this->assertNull(app(DonorDeferralRepository::class)->standingDeferralFor($donor->id));
    }

    public function test_only_this_centres_active_one_is_lifted_and_only_by_the_physician(): void
    {
        $ended = $this->screened(screening: ['deferred_until' => OperationalDay::today()->subDay()->toDateString()]);
        $theirs = $this->screened(at: Facility::factory()->approved()->create());
        $mine = $this->screened(screening: ['deferred_until' => OperationalDay::today()->addDays(5)->toDateString()]);
        $receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$ended->id}/lift", ['source' => 'screening', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'deferral_expired');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$theirs->id}/lift", ['source' => 'screening', 'reason' => 'x'])
            ->assertNotFound();

        $this->actingAs($receptionist)
            ->postJson("/api/blood-center/deferrals/{$mine->id}/lift", ['source' => 'screening', 'reason' => 'x'])
            ->assertForbidden();
    }

    public function test_it_sits_with_the_page_own_deferrals_newest_first_and_is_found_by_code(): void
    {
        $older = DonorDeferral::factory()->create([
            'facility_id' => $this->facility->id,
            'deferred_on' => OperationalDay::today()->subDays(3)->toDateString(),
            'reason_code' => 'R5',
        ]);
        $screening = $this->screened(screening: ['deferred_until' => OperationalDay::today()->addDays(5)->toDateString(), 'deferral_code' => 'R2-Low']);

        $this->assertSame(['counter', 'manual'], array_column($this->rows(), 'source'));

        $code = 'DON-'.str_pad((string) $screening->donation_id, 6, '0', STR_PAD_LEFT);
        $this->assertSame([$screening->id], collect($this->rows("search={$code}"))->pluck('id')->all());
        $this->assertSame(['counter'], array_column($this->rows('search=r2-low'), 'source'));
        $this->assertSame([$older->id], collect($this->rows('search=R5'))->pluck('id')->all());

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('data.0.source', 'manual');
    }
}
