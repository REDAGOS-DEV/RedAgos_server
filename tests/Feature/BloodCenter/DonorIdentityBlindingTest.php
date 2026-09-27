<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Enums\StaffRole;
use App\Models\BloodCollection;
use App\Models\BloodComponent;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Who may see who a donation belongs to, and who may read their clinical record.
 *
 * The laboratory and inventory roles work the bag: a segment number and a
 * donation id are enough to match a sample to its record, so the donor's name
 * is withheld from them. The roles that meet the donor in person keep it.
 */
class DonorIdentityBlindingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $donor;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->donor = User::factory()->donor()->create(['first_name' => 'Rosalinda', 'last_name' => 'Magbanua']);

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $this->donor->id,
            'status' => DonationStatus::Collected,
        ]);

        // The factory draws the bag for a collected donation; give it a known
        // segment number to find.
        BloodCollection::where('donation_id', $this->donation->id)->update(['segment_number' => 'SEG-7781']);
    }

    private function staff(StaffRole $role): User
    {
        return User::factory()->bloodCenterStaff($this->facility, $role)->create();
    }

    public function test_the_laboratory_roles_see_the_bag_but_not_the_donor(): void
    {
        foreach ([StaffRole::SerologyTechnologist, StaffRole::ComponentTechnologist, StaffRole::ProcessingAssistant] as $role) {
            $response = $this->actingAs($this->staff($role))
                ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
                ->assertOk()
                ->assertJsonPath('donor.blinded', true)
                ->assertJsonPath('collection.segment_number', 'SEG-7781');

            $donor = $response->json('donor');

            $this->assertArrayNotHasKey('full_name', $donor, $role->value);
            $this->assertArrayNotHasKey('donor_code', $donor, $role->value);
            $this->assertArrayNotHasKey('uuid', $donor, $role->value);
            $this->assertStringNotContainsString('Magbanua', $response->getContent(), $role->value);
        }
    }

    public function test_the_laboratory_queue_is_blinded_too(): void
    {
        $response = $this->actingAs($this->staff(StaffRole::SerologyTechnologist))
            ->getJson('/api/blood-center/laboratory/queue?stage=testing')
            ->assertOk()
            ->assertJsonPath('data.0.donor.blinded', true);

        $this->assertStringNotContainsString('Magbanua', $response->getContent());
    }

    public function test_the_lab_supervisor_and_a_centre_supervisor_see_who_it_is(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();

        foreach ([$this->staff(StaffRole::LabSupervisor), $supervisor] as $viewer) {
            $this->actingAs($viewer)
                ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
                ->assertOk()
                ->assertJsonPath('donor.blinded', false)
                ->assertJsonPath('donor.full_name', 'Rosalinda Magbanua');
        }
    }

    public function test_the_intake_queue_names_the_segment_not_the_donor(): void
    {
        $this->donation->update(['status' => DonationStatus::Completed]);

        DonationComponent::factory()->create([
            'donation_id' => $this->donation->id,
            'component_id' => BloodComponent::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->staff(StaffRole::InventoryControlOfficer))
            ->getJson('/api/blood-center/inventory/intake-queue')
            ->assertOk()
            ->assertJsonPath('data.0.donation_id', $this->donation->id)
            ->assertJsonPath('data.0.segment_number', 'SEG-7781')
            ->assertJsonPath('data.0.donor.blinded', true);

        $this->assertStringNotContainsString('Magbanua', $response->getContent());
    }

    public function test_the_counter_sees_an_outcome_but_only_the_physician_sees_the_clinical_detail(): void
    {
        $open = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => User::factory()->donor()->create()->id,
            'donation_date' => now(),
            'status' => DonationStatus::Screening,
        ]);

        DonationScreening::factory()->create([
            'donation_id' => $open->id,
            'facility_id' => $this->facility->id,
            'outcome' => ScreeningOutcome::Accepted,
            'systolic_bp' => 118,
            'haemoglobin_g_dl' => 13.9,
        ]);

        $receptionist = $this->actingAs($this->staff(StaffRole::MedicalReceptionist))
            ->getJson('/api/blood-center/collection/queue')
            ->assertOk();

        $screening = collect($receptionist->json('in_progress'))->firstWhere('id', $open->id)['screening'];

        $this->assertSame('accepted', $screening['outcome']);
        $this->assertTrue($screening['restricted']);
        $this->assertArrayNotHasKey('systolic_bp', $screening);
        $this->assertArrayNotHasKey('haemoglobin_g_dl', $screening);

        $physician = $this->actingAs($this->staff(StaffRole::ScreeningPhysician))
            ->getJson('/api/blood-center/collection/queue')
            ->assertOk();

        $clinical = collect($physician->json('in_progress'))->firstWhere('id', $open->id)['screening'];

        $this->assertFalse($clinical['restricted']);
        $this->assertSame(118, $clinical['systolic_bp']);
    }

    public function test_issuance_and_billing_reach_no_donor_at_all(): void
    {
        foreach ([StaffRole::DispatchCoordinator, StaffRole::ItDataClerk, StaffRole::BillingClerk] as $role) {
            $this->actingAs($this->staff($role))
                ->getJson('/api/blood-center/donors')
                ->assertForbidden();
        }
    }

    public function test_only_the_physician_reads_the_donation_history(): void
    {
        $this->actingAs($this->staff(StaffRole::ScreeningPhysician))
            ->getJson("/api/blood-center/donors/{$this->donor->uuid}/history")
            ->assertOk()
            ->assertJsonPath('donations.0.id', $this->donation->id);

        $this->actingAs($this->staff(StaffRole::MedicalReceptionist))
            ->getJson("/api/blood-center/donors/{$this->donor->uuid}/history")
            ->assertForbidden();
    }

    public function test_the_chair_confirms_identity_by_lookup_without_the_registration_record(): void
    {
        $code = 'DONOR-'.str_pad((string) $this->donor->id, 6, '0', STR_PAD_LEFT);

        $response = $this->actingAs($this->staff(StaffRole::Phlebotomist))
            ->getJson("/api/blood-center/donors/lookup?type=donor_code&value={$code}")
            ->assertOk()
            ->assertJsonPath('full_name', 'Rosalinda Magbanua')
            ->assertJsonPath('restricted', true);

        $this->assertArrayNotHasKey('address', $response->json());
        $this->assertArrayNotHasKey('valid_id_number', $response->json());
        $this->assertArrayHasKey('prior_deferral', $response->json());
    }
}
