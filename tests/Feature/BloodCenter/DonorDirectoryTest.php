<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Donors are global; donor records are not.
 *
 * A centre browses only the donors it has dealt with, but an exact identifier
 * finds anyone — otherwise a walk-in who last donated elsewhere gets registered
 * twice and their donation history forks, which breaks the 56-day interval the
 * eligibility rules read. What the second path returns is a standardised
 * summary: counts and dates, never another centre's records.
 */
class DonorDirectoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();
    }

    /**
     * A donor who has donated at the given facility.
     */
    private function donorAt(Facility $facility, int $completedDonations = 1): User
    {
        $donor = User::factory()->donor()->create();

        Donation::factory()->count($completedDonations)->create([
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'status' => 'completed',
        ]);

        return $donor;
    }

    public function test_browsing_returns_only_donors_this_facility_has_dealt_with(): void
    {
        $mine = $this->donorAt($this->facility);
        $theirs = $this->donorAt(Facility::factory()->approved()->create());

        $uuids = collect(
            $this->actingAs($this->staff)
                ->getJson('/api/blood-center/donors')
                ->assertOk()
                ->json('data')
        )->pluck('uuid');

        $this->assertContains($mine->uuid, $uuids);
        $this->assertNotContains($theirs->uuid, $uuids);
    }

    public function test_a_donor_with_only_an_appointment_here_is_browsable(): void
    {
        $donor = User::factory()->donor()->create();

        DonationAppointment::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $uuids = collect($this->actingAs($this->staff)->getJson('/api/blood-center/donors')->json('data'))->pluck('uuid');

        $this->assertContains($donor->uuid, $uuids);
    }

    public function test_an_exact_donor_code_finds_a_donor_from_another_facility(): void
    {
        $theirs = $this->donorAt(Facility::factory()->approved()->create(), completedDonations: 3);
        $code = 'DONOR-'.str_pad((string) $theirs->id, 6, '0', STR_PAD_LEFT);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/lookup?type=donor_code&value={$code}")
            ->assertOk()
            ->assertJsonPath('scope', 'cross_facility')
            ->assertJsonPath('restricted', true)
            ->assertJsonPath('donation_summary.total_donations', 3);
    }

    public function test_the_cross_facility_view_withholds_detailed_records(): void
    {
        $theirs = $this->donorAt(Facility::factory()->approved()->create());
        DonorProfile::where('donor_id', $theirs->id)->update(['address' => '123 Secret Street']);

        $body = $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$theirs->uuid}")
            ->assertOk()
            ->json();

        // Identity and a summary, so staff can confirm the person in front of
        // them and judge eligibility. Nothing else.
        $this->assertArrayHasKey('donation_summary', $body);
        $this->assertArrayNotHasKey('address', $body);
        $this->assertArrayNotHasKey('valid_id_number', $body);
        $this->assertArrayNotHasKey('donations_at_this_facility', $body);
    }

    public function test_an_own_facility_donor_returns_the_full_record(): void
    {
        $mine = $this->donorAt($this->facility);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$mine->uuid}")
            ->assertOk()
            ->assertJsonPath('scope', 'own_facility')
            ->assertJsonPath('restricted', false)
            ->assertJsonStructure(['address', 'valid_id_number', 'donations_at_this_facility']);
    }

    public function test_detailed_history_is_refused_for_another_facilitys_donor(): void
    {
        $theirs = $this->donorAt(Facility::factory()->approved()->create());

        // Deliberately 403 with a reason rather than an empty list: an empty
        // array would read as "this donor has never donated", which is false.
        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$theirs->uuid}/history")
            ->assertForbidden()
            ->assertJsonPath('code', 'donor_not_at_facility');
    }

    public function test_history_returns_only_this_facilitys_donations(): void
    {
        $donor = $this->donorAt($this->facility, completedDonations: 2);
        Donation::factory()->count(4)->create([
            'donor_id' => $donor->id,
            'facility_id' => Facility::factory()->approved()->create()->id,
            'status' => 'completed',
        ]);

        $body = $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$donor->uuid}/history")
            ->assertOk()
            ->json();

        $this->assertCount(2, $body['donations'], 'Only this facility\'s donations may appear in detail.');
    }

    public function test_lookup_is_exact_match_only(): void
    {
        $theirs = $this->donorAt(Facility::factory()->approved()->create());

        // A partial identifier must not resolve, or the cross-facility path
        // becomes a way to enumerate the donor register.
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/donors/lookup?type=email&value='.substr($theirs->email, 0, 4))
            ->assertNotFound();
    }

    public function test_lookup_rejects_a_name_search(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/donors/lookup?type=last_name&value=Guerra')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_every_cross_facility_read_is_audited(): void
    {
        $theirs = $this->donorAt(Facility::factory()->approved()->create());

        $this->actingAs($this->staff)->getJson("/api/blood-center/donors/{$theirs->uuid}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'donor.viewed',
            'actor_id' => $this->staff->id,
            'auditable_id' => $theirs->id,
        ]);
    }

    public function test_laboratory_staff_cannot_browse_donors(): void
    {
        $lab = User::factory()->bloodCenterStaff($this->facility, Department::Laboratory)->create();

        $this->actingAs($lab)->getJson('/api/blood-center/donors')->assertForbidden();
    }

    public function test_a_supervisor_may_browse_donors(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->actingAs($supervisor)->getJson('/api/blood-center/donors')->assertOk();
    }
}
