<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The parts of the inventory summary a caller can ask for on their own.
 *
 * The task counts need only the near-expiry figures, and Blood Inventory never
 * draws the seven-day trend, which is rebuilt from the whole unit history.
 * Neither should pay for the rest.
 */
class InventorySummaryPartsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->bloodCenterStaff()->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_near_expiry_counts_only_the_callers_own_stock(): void
    {
        $this->unit($this->staff->facility_id, BloodUnitStatus::Available, 2);
        $this->unit($this->staff->facility_id, BloodUnitStatus::Available, 5);
        $this->unit($this->staff->facility_id, BloodUnitStatus::Available, 30);
        $this->unit($this->staff->facility_id, BloodUnitStatus::Expired, -3);

        // Another centre's bag, expiring tomorrow, is none of this centre's business.
        $this->unit(Facility::factory()->approved()->create()->id, BloodUnitStatus::Available, 1);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/near-expiry')
            ->assertOk()
            ->assertExactJsonStructure(['near_expiry' => ['expired', 'within_3_days', 'within_7_days'], 'as_of']);

        $this->assertSame(['expired' => 1, 'within_3_days' => 1, 'within_7_days' => 2], $response->json('near_expiry'));
    }

    public function test_near_expiry_gives_the_same_figures_as_the_summary(): void
    {
        $this->unit($this->staff->facility_id, BloodUnitStatus::Available, 1);
        $this->unit($this->staff->facility_id, BloodUnitStatus::Available, 6);

        $summary = $this->actingAs($this->staff)->getJson('/api/blood-center/inventory/summary')->json('near_expiry');
        $part = $this->actingAs($this->staff)->getJson('/api/blood-center/inventory/near-expiry')->json('near_expiry');

        $this->assertSame($summary, $part);
    }

    public function test_the_summary_carries_the_trend_unless_told_not_to(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonCount(8, 'trend');

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary?trend=0')
            ->assertOk()
            ->assertJsonMissingPath('trend')
            ->assertJsonStructure(['totals', 'by_blood_type', 'near_expiry', 'issued_today', 'expiry_sweep', 'as_of']);
    }

    private function unit(int $facilityId, BloodUnitStatus $status, int $expiresInDays): void
    {
        BloodUnit::factory()->create([
            'facility_id' => $facilityId,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => Donation::factory()->create([
                'facility_id' => $facilityId,
                'donor_id' => $this->donorProfile->donor_id,
            ])->id,
            'status' => $status,
            'expiry_date' => OperationalDay::today()->addDays($expiresInDays)->toDateString(),
        ]);
    }
}
