<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\TestResult;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationTestResult;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The last thing between an untested bag and a patient.
 *
 * `completed` is what blood-unit intake gates on, and this department is the
 * only place it can be written. Every guard here exists so a donation cannot
 * reach that status without a passing result and a declared yield.
 */
class LaboratoryProcessingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $lab;

    private BloodType $bloodType;

    private BloodComponent $component;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->lab = User::factory()->bloodCenterStaff($this->facility, Department::Laboratory)->create();

        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        // donor() already creates the profile and its blood type, so the type
        // is read off the donor rather than forced -- BloodTypeFactory draws
        // from the same eight real codes, and inventing a ninth here collides
        // on the unique label.
        $donor = User::factory()->donor()->create();
        $profile = $donor->donorProfile;
        $this->bloodType = $profile->bloodType;

        // Handed over by Donor/Collection.
        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $profile->donor_id,
            'status' => DonationStatus::Collected,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordResult(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->lab)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/results",
            [
                'result' => 'passed',
                'blood_type_id' => $this->bloodType->id,
                ...$overrides,
            ]
        );
    }

    private function declareComponents(int $quantity = 2): TestResponse
    {
        return $this->actingAs($this->lab)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/components",
            ['components' => [['component_id' => $this->component->id, 'quantity' => $quantity]]]
        );
    }

    private function complete(): TestResponse
    {
        return $this->actingAs($this->lab)->patchJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/status",
            ['status' => 'completed']
        );
    }

    public function test_the_queue_shows_donations_handed_over_by_collection(): void
    {
        $ids = collect(
            $this->actingAs($this->lab)
                ->getJson('/api/blood-center/laboratory/queue')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertContains($this->donation->id, $ids);
    }

    public function test_recording_a_result_moves_the_donation_to_tested(): void
    {
        $this->recordResult()
            ->assertCreated()
            ->assertJsonPath('data.status', 'tested')
            ->assertJsonPath('data.test_result.result', 'passed');

        $this->assertSame(DonationStatus::Tested, $this->donation->fresh()->status);
    }

    public function test_the_recording_staff_member_is_taken_from_the_token(): void
    {
        $this->recordResult()->assertCreated();

        $this->assertDatabaseHas('donation_test_results', [
            'donation_id' => $this->donation->id,
            'recorded_by' => $this->lab->id,
        ]);
    }

    public function test_a_result_cannot_be_recorded_before_collection(): void
    {
        $this->donation->update(['status' => DonationStatus::Registered]);

        $this->recordResult()
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_collected');
    }

    public function test_correcting_a_result_edits_the_same_row(): void
    {
        $this->recordResult()->assertCreated();
        $this->recordResult(['result' => 'inconclusive'])->assertCreated();

        // One result set per donation, so there is never an ambiguity about
        // which result cleared the blood.
        $this->assertSame(1, DonationTestResult::where('donation_id', $this->donation->id)->count());
        $this->assertSame(TestResult::Inconclusive, DonationTestResult::where('donation_id', $this->donation->id)->value('result'));
    }

    public function test_a_typed_blood_type_contradicting_the_donor_record_is_refused(): void
    {
        $other = BloodType::factory()->create(['code' => 'XX-TEST', 'label' => 'XX-TEST']);

        // A person's blood type does not change, so a mismatch means one of the
        // two records is wrong — and blood_units derives its type from the
        // donor profile.
        $this->recordResult(['blood_type_id' => $other->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'blood_type_mismatch');
    }

    public function test_the_full_chain_clears_a_donation_for_issue(): void
    {
        $this->recordResult()->assertCreated();
        $this->declareComponents()->assertCreated();

        $this->complete()
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(DonationStatus::Completed, $this->donation->fresh()->status);
    }

    public function test_a_reactive_donation_can_never_be_cleared_for_issue(): void
    {
        $this->recordResult(['result' => 'reactive'])->assertCreated();
        $this->declareComponents()->assertCreated();

        // The rule the whole department exists for.
        $this->complete()
            ->assertStatus(422)
            ->assertJsonPath('code', 'result_not_passed');

        $this->assertNotSame(DonationStatus::Completed, $this->donation->fresh()->status);
    }

    public function test_an_inconclusive_donation_can_never_be_cleared_for_issue(): void
    {
        $this->recordResult(['result' => 'inconclusive'])->assertCreated();
        $this->declareComponents()->assertCreated();

        $this->complete()
            ->assertStatus(422)
            ->assertJsonPath('code', 'result_not_passed');
    }

    public function test_a_donation_cannot_be_cleared_without_a_result(): void
    {
        $this->complete()
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_tested');
    }

    public function test_a_donation_cannot_be_cleared_without_a_declared_yield(): void
    {
        $this->recordResult()->assertCreated();

        $this->complete()
            ->assertStatus(409)
            ->assertJsonPath('code', 'components_missing');
    }

    public function test_components_cannot_be_declared_before_a_result(): void
    {
        $this->declareComponents()
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_tested');
    }

    public function test_the_same_component_cannot_be_declared_twice(): void
    {
        $this->recordResult()->assertCreated();

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/components", [
                'components' => [
                    ['component_id' => $this->component->id, 'quantity' => 1],
                    ['component_id' => $this->component->id, 'quantity' => 2],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('components');
    }

    public function test_a_reactive_donation_may_be_rejected(): void
    {
        $this->recordResult(['result' => 'reactive'])->assertCreated();

        $this->actingAs($this->lab)
            ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_the_laboratory_cannot_set_a_collection_status(): void
    {
        foreach (['registered', 'screening', 'collected', 'tested'] as $status) {
            $this->actingAs($this->lab)
                ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", ['status' => $status])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }
    }

    public function test_another_facilitys_donation_is_not_found(): void
    {
        $foreign = Donation::factory()->create([
            'facility_id' => Facility::factory()->approved()->create()->id,
            'donor_id' => $this->donation->donor_id,
            'status' => DonationStatus::Collected,
        ]);

        $this->actingAs($this->lab)
            ->postJson("/api/blood-center/laboratory/donations/{$foreign->id}/results", [
                'result' => 'passed',
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertNotFound();
    }

    public function test_collection_staff_cannot_reach_the_laboratory(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();

        $this->actingAs($collection)->getJson('/api/blood-center/laboratory/queue')->assertForbidden();

        $this->actingAs($collection)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/results", [
                'result' => 'passed',
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertForbidden();
    }

    public function test_inventory_staff_cannot_clear_a_donation_for_issue(): void
    {
        $inventory = User::factory()->bloodCenterStaff($this->facility, Department::Inventory)->create();

        $this->actingAs($inventory)
            ->patchJson("/api/blood-center/laboratory/donations/{$this->donation->id}/status", ['status' => 'completed'])
            ->assertForbidden();
    }
}
