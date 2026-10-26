<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\IdentityStatus;
use App\Enums\StaffRole;
use App\Enums\ValidIdType;
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
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
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

    public function test_browsing_lists_every_donor_in_redagos_and_says_whose_they_are(): void
    {
        // Global across the blood centres (project owner, 2026-10-25).
        $mine = $this->donorAt($this->facility);
        $theirs = $this->donorAt(Facility::factory()->approved()->create());

        $rows = collect(
            $this->actingAs($this->staff)
                ->getJson('/api/blood-center/donors')
                ->assertOk()
                ->json('data')
        )->keyBy('uuid');

        $this->assertSame('own_facility', $rows[$mine->uuid]['scope']);
        $this->assertSame('cross_facility', $rows[$theirs->uuid]['scope']);
    }

    public function test_the_export_stays_with_this_centres_own_donors(): void
    {
        $mine = $this->donorAt($this->facility);
        $theirs = $this->donorAt(Facility::factory()->approved()->create());
        $receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();

        $csv = $this->actingAs($receptionist)->get('/api/blood-center/donors/export')->assertOk()->streamedContent();

        $this->assertStringContainsString($mine->last_name, $csv);
        $this->assertStringNotContainsString($theirs->last_name, $csv);
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
            ->assertJsonPath('restricted', false)
            ->assertJsonPath('donation_summary.total_donations', 3);
    }

    public function test_another_centres_donor_opens_as_the_full_record(): void
    {
        // The full record for every centre (project owner, 2026-10-25).
        $theirs = $this->donorAt(Facility::factory()->approved()->create());
        DonorProfile::where('donor_id', $theirs->id)->update(['address' => '123 Main Street']);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$theirs->uuid}")
            ->assertOk()
            ->assertJsonPath('scope', 'cross_facility')
            ->assertJsonPath('restricted', false)
            ->assertJsonPath('address', '123 Main Street')
            ->assertJsonPath('donations_at_this_facility', 0)
            ->assertJsonStructure(['valid_id_number', 'eligibility']);
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

    public function test_an_own_facility_donor_shows_identity_status_but_never_the_document(): void
    {
        $mine = $this->donorAt($this->facility);
        DonorProfile::where('donor_id', $mine->id)->update([
            'valid_id_type' => ValidIdType::DriversLicense->value,
            'valid_id_image_path' => 'identity-documents/secret.jpg',
            'identity_status' => IdentityStatus::Verified->value,
        ]);

        $body = $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$mine->uuid}")
            ->assertOk()
            ->assertJsonPath('identity_status', IdentityStatus::Verified->value)
            ->assertJsonPath('valid_id_type', ValidIdType::DriversLicense->value)
            ->getContent();

        // Status and type tell staff the ID was checked. The photograph itself
        // is for the administrator who reviewed it; staff match the physical
        // card at the counter.
        $this->assertStringNotContainsString('valid_id_image_path', $body);
        $this->assertStringNotContainsString('secret.jpg', $body);
    }

    public function test_another_centres_donations_stay_with_it(): void
    {
        $theirs = $this->donorAt(Facility::factory()->approved()->create());

        // Each centre's own history (project owner, 2026-10-25): none here,
        // and the scope says why rather than reading as "never donated".
        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/donors/{$theirs->uuid}/history")
            ->assertOk()
            ->assertJsonPath('scope', 'cross_facility')
            ->assertJsonCount(0, 'donations');
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
        $lab = User::factory()->bloodCenterStaff($this->facility, Department::Testing)->create();

        $this->actingAs($lab)->getJson('/api/blood-center/donors')->assertForbidden();
    }

    public function test_neither_management_level_browses_donors(): void
    {
        // Donor records are Collection's. The Admin and the Supervisor watch
        // the centre's numbers, not its donors.
        foreach ([
            User::factory()->bloodCenterAdmin($this->facility)->create(),
            User::factory()->bloodCenterSupervisor($this->facility)->create(),
        ] as $manager) {
            $this->actingAs($manager)->getJson('/api/blood-center/donors')->assertForbidden();
        }
    }
}
