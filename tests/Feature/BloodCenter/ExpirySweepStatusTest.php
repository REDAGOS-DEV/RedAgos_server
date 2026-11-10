<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\User;
use App\Repository\InventoryRepository;
use App\Service\InventoryService;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The inventory summary says when the nightly expiry sweep last completed.
 *
 * Until it runs, a unit past its date still reads `available` and is counted
 * as stock, so Blood Inventory and the Issuance dashboard warn when the sweep
 * has not completed within 26 hours, or never has. Only a run that reached the
 * end counts: the sweep writes its row last.
 */
class ExpirySweepStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->bloodCenterStaff()->create();
    }

    public function test_with_no_completed_sweep_on_record_it_is_overdue(): void
    {
        $this->summary()
            ->assertJsonPath('expiry_sweep.last_completed_at', null)
            ->assertJsonPath('expiry_sweep.overdue', true);
    }

    public function test_a_sweep_that_just_completed_is_not_overdue(): void
    {
        $this->artisan('inventory:expire-units')->assertSuccessful();

        $completedAt = AuditLog::query()->where('action', 'inventory.expiry_swept')->value('created_at');

        $this->summary()
            ->assertJsonPath('expiry_sweep.last_completed_at', $completedAt->toImmutable()->toIso8601String())
            ->assertJsonPath('expiry_sweep.overdue', false);
    }

    public function test_a_sweep_older_than_26_hours_is_overdue(): void
    {
        $this->travel(-(InventoryService::EXPIRY_SWEEP_OVERDUE_HOURS + 1))->hours();
        $this->artisan('inventory:expire-units')->assertSuccessful();
        $this->travelBack();

        $this->summary()->assertJsonPath('expiry_sweep.overdue', true);
    }

    public function test_a_sweep_within_26_hours_is_not_overdue(): void
    {
        $this->travel(-(InventoryService::EXPIRY_SWEEP_OVERDUE_HOURS - 1))->hours();
        $this->artisan('inventory:expire-units')->assertSuccessful();
        $this->travelBack();

        $this->summary()->assertJsonPath('expiry_sweep.overdue', false);
    }

    public function test_the_newest_completed_sweep_is_the_one_reported(): void
    {
        $this->travel(-3)->days();
        $this->artisan('inventory:expire-units')->assertSuccessful();
        $this->travelBack();

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->summary()->assertJsonPath('expiry_sweep.overdue', false);
    }

    public function test_a_sweep_that_fails_part_way_records_no_completed_run(): void
    {
        $this->dueUnit();

        // The UPDATE reports fewer rows than were locked, which stops the run
        // before its closing row is written.
        $this->partialMock(InventoryRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('markExpired')->andReturn(0);
        });

        try {
            Artisan::call('inventory:expire-units');
            $this->fail('The sweep should have stopped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('expiry sweep', $exception->getMessage());
        }

        $this->assertFalse(AuditLog::query()->where('action', 'inventory.expiry_swept')->exists());

        $this->summary()
            ->assertJsonPath('expiry_sweep.last_completed_at', null)
            ->assertJsonPath('expiry_sweep.overdue', true);
    }

    private function summary(): TestResponse
    {
        return $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk();
    }

    /**
     * An available unit at the staff member's centre, past its expiry date.
     */
    private function dueUnit(): void
    {
        $bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $bloodType->id,
        ]);

        BloodUnit::factory()->create([
            'facility_id' => $this->staff->facility_id,
            'blood_type_id' => $bloodType->id,
            'component_id' => BloodComponent::factory()->create()->id,
            'donation_id' => Donation::factory()->create([
                'facility_id' => $this->staff->facility_id,
                'donor_id' => $donorProfile->donor_id,
            ])->id,
            'status' => BloodUnitStatus::Available,
            'expiry_date' => OperationalDay::today()->subDay()->toDateString(),
        ]);
    }
}
