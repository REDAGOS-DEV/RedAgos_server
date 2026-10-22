<?php

namespace Tests\Feature\Donor;

use App\Enums\StaffRole;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * GET /api/donors/deferral: what the donor portal may tell a donor about
 * their own deferral, and, as much, what it must not.
 */
class DeferralStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->donor = User::factory()->donor()->create();
    }

    private function screening(string $state, ?User $donor = null): DonationScreening
    {
        $donation = Donation::factory()->create([
            'donor_id' => ($donor ?? $this->donor)->id,
            'facility_id' => $this->facility->id,
            'donation_date' => now()->subMonths(2),
        ]);

        return DonationScreening::factory()->{$state}()->create([
            'donation_id' => $donation->id,
            'facility_id' => $this->facility->id,
            'recorded_by' => $this->staff->id,
            'screened_at' => now()->subMonths(2),
        ]);
    }

    public function test_a_donor_with_no_deferral_is_told_none(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertExactJson(['deferred' => false, 'temporary' => null, 'until' => null, 'since' => null]);
    }

    public function test_a_temporary_deferral_recorded_by_hand_runs_to_its_end_date(): void
    {
        $endsOn = OperationalDay::today()->addDays(20)->toDateString();

        DonorDeferral::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'ends_on' => $endsOn,
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertJsonPath('deferred', true)
            ->assertJsonPath('temporary', true)
            ->assertJsonPath('until', $endsOn);
    }

    public function test_a_lifted_deferral_no_longer_counts(): void
    {
        DonorDeferral::factory()->lifted()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertJsonPath('deferred', false);
    }

    public function test_a_temporary_deferral_past_its_end_no_longer_counts(): void
    {
        DonorDeferral::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'deferred_on' => OperationalDay::today()->subDays(40)->toDateString(),
            'ends_on' => OperationalDay::today()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertJsonPath('deferred', false);
    }

    public function test_a_permanent_deferral_has_no_end_and_is_not_called_temporary(): void
    {
        $this->screening('permanentlyDeferred');

        $this->actingAs($this->donor)
            ->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertJsonPath('deferred', true)
            ->assertJsonPath('temporary', false)
            ->assertJsonPath('until', null);
    }

    /**
     * A reactive result is told through counselling, not the portal: the
     * answer must read exactly like any other open-ended deferral.
     */
    public function test_a_laboratory_deferral_reads_the_same_as_a_screening_one(): void
    {
        $donation = Donation::factory()->rejectedAfterCollection()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'donation_date' => now()->subMonths(2),
        ]);
        CounsellingReferral::create([
            'donation_id' => $donation->id,
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $response = $this->actingAs($this->donor)->getJson('/api/donors/deferral')->assertOk();

        $this->assertSame(['deferred', 'temporary', 'until', 'since'], array_keys($response->json()));
        $response->assertJsonPath('temporary', false)->assertJsonPath('until', null);
        $this->assertStringNotContainsStringIgnoringCase('serolog', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('reactive', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('counsel', $response->getContent());
    }

    public function test_the_reason_is_never_sent(): void
    {
        DonorDeferral::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'reason' => 'Low haemoglobin',
        ]);

        $response = $this->actingAs($this->donor)->getJson('/api/donors/deferral')->assertOk();

        $this->assertStringNotContainsString('haemoglobin', $response->getContent());
        $response->assertJsonMissingPath('reason');
    }

    public function test_a_donor_sees_only_their_own_record(): void
    {
        $other = User::factory()->donor()->create();
        $this->screening('permanentlyDeferred', $other);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertJsonPath('deferred', false);
    }

    public function test_staff_cannot_use_the_donor_endpoint(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/donors/deferral')
            ->assertForbidden();
    }

    public function test_it_needs_a_signed_in_donor(): void
    {
        $this->getJson('/api/donors/deferral')->assertUnauthorized();
    }
}
