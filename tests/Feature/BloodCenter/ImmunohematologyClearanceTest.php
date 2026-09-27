<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RecordsTyping;
use Tests\TestCase;

/**
 * Immunohematology's clearance: issued only for a typing that can be trusted.
 */
class ImmunohematologyClearanceTest extends TestCase
{
    use LazilyRefreshDatabase, RecordsTyping;

    private Facility $facility;

    private User $technologist;

    private BloodType $bloodType;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();

        $donor = User::factory()->donor()->create();
        $this->bloodType = $donor->donorProfile->bloodType;

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $donor->id,
            'status' => DonationStatus::Collected,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function type(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technologist)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/immunohematology",
            $this->concordantTyping($this->bloodType, $overrides)
        );
    }

    private function otherGroup(): string
    {
        return str_starts_with($this->bloodType->code, 'O') ? 'A' : 'O';
    }

    private function cleared(): bool
    {
        return DonationClearance::where('donation_id', $this->donation->id)->where('kind', 'immunohematology')->exists();
    }

    public function test_a_concordant_negative_typing_is_cleared(): void
    {
        $this->type()
            ->assertCreated()
            ->assertJsonPath('clearance_hold', null)
            ->assertJsonPath('data.clearances.immunohematology', true)
            ->assertJsonPath('data.clearances.tti', false);

        $this->assertTrue($this->cleared());
    }

    public function test_a_forward_reverse_discrepancy_is_saved_but_held(): void
    {
        $this->type(['reverse_group' => $this->otherGroup()])
            ->assertCreated()
            ->assertJsonPath('clearance_hold', 'abo_discrepancy')
            ->assertJsonPath('data.immunohematology.clearance_hold', 'abo_discrepancy');

        $this->assertFalse($this->cleared());
    }

    public function test_a_positive_antibody_screen_is_saved_but_held(): void
    {
        $this->type(['antibody_screen' => 'positive'])
            ->assertCreated()
            ->assertJsonPath('clearance_hold', 'antibody_screen_positive');

        $this->assertFalse($this->cleared());
    }

    public function test_the_typing_must_be_the_forward_group(): void
    {
        $this->type(['forward_group' => $this->otherGroup()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('forward_group');
    }

    public function test_grouping_and_screen_are_required(): void
    {
        $this->actingAs($this->technologist)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/immunohematology", [
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['forward_group', 'reverse_group', 'antibody_screen']);
    }

    public function test_a_cleared_typing_is_locked_even_for_a_supervisor(): void
    {
        $this->type()->assertCreated();

        $this->type(['notes' => 'Second look'], User::factory()->bloodCenterSupervisor($this->facility)->create())
            ->assertStatus(409)
            ->assertJsonPath('code', 'results_cleared');
    }

    public function test_testing_types_the_unit_and_no_other_department_can(): void
    {
        $this->type([], User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create())
            ->assertCreated();

        foreach ([StaffRole::ComponentTechnologist, StaffRole::InventoryControlOfficer, StaffRole::ScreeningPhysician] as $role) {
            $this->type([], User::factory()->bloodCenterStaff($this->facility, $role)->create())
                ->assertForbidden();
        }
    }

    public function test_each_department_has_a_queue_of_what_it_has_not_cleared(): void
    {
        $queue = fn (string $stage): array => collect(
            $this->actingAs($this->technologist)
                ->getJson("/api/blood-center/laboratory/queue?stage={$stage}")
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($this->donation->id, $queue('immunohematology'));
        $this->assertContains($this->donation->id, $queue('serology'));

        $this->type()->assertCreated();

        $this->assertNotContains($this->donation->id, $queue('immunohematology'));
        $this->assertContains($this->donation->id, $queue('serology'));
    }
}
