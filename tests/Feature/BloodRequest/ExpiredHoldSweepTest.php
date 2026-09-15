<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BloodUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * What happens to a bag that runs out of date while it is promised to somebody.
 *
 * Until allocation existed this was an acknowledged dead end: the sweep touches
 * available units only, so a reserved unit could pass its expiry and keep
 * saying `reserved` with nothing in the system able to release it. The hold now
 * goes first, and the unit expires in the same run.
 */
class ExpiredHoldSweepTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells']);
        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_a_hold_on_an_out_of_date_unit_is_given_up_and_the_unit_expires(): void
    {
        $request = $this->request();
        $unit = $this->unit(['id' => 'STALE-HOLD', 'expiry_date' => $this->inDays(-1)], BloodUnitStatus::Reserved);
        $hold = RequestAllocation::factory()->holding($request, $unit)->create();

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(
            AllocationStatus::Cancelled,
            $hold->fresh()->status,
            'The promise has to be given up before the bag can be relabelled.'
        );
        $this->assertSame(BloodUnitStatus::Expired, $unit->fresh()->status);
        $this->assertNotNull($hold->fresh()->cancelled_at);
    }

    public function test_the_released_hold_records_why_it_was_given_up(): void
    {
        $unit = $this->unit(['id' => 'STALE', 'expiry_date' => $this->inDays(-2)], BloodUnitStatus::Reserved);
        $hold = RequestAllocation::factory()->holding($this->request(), $unit)->create();

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(
            'Unit passed its expiry date while reserved.',
            $hold->fresh()->cancellation_reason
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'allocation.expired_hold_released',
            'auditable_id' => 'STALE',
        ]);
    }

    public function test_a_hold_on_an_in_date_unit_is_left_alone(): void
    {
        $unit = $this->unit(['id' => 'FRESH-HOLD', 'expiry_date' => $this->inDays(10)], BloodUnitStatus::Reserved);
        $hold = RequestAllocation::factory()->holding($this->request(), $unit)->create();

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(AllocationStatus::Allocated, $hold->fresh()->status);
        $this->assertSame(BloodUnitStatus::Reserved, $unit->fresh()->status);
    }

    public function test_an_already_dispatched_unit_is_not_clawed_back(): void
    {
        $unit = $this->unit(['id' => 'GONE', 'expiry_date' => $this->inDays(-1)], BloodUnitStatus::Issued);
        $hold = RequestAllocation::factory()->holding($this->request(), $unit)->released()->create();

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertSame(
            AllocationStatus::Released,
            $hold->fresh()->status,
            'A bag that has physically left the building is no longer the sweep\'s business.'
        );
        $this->assertSame(BloodUnitStatus::Issued, $unit->fresh()->status);
    }

    public function test_the_run_row_reports_how_many_holds_it_released(): void
    {
        $unit = $this->unit(['id' => 'STALE-1', 'expiry_date' => $this->inDays(-1)], BloodUnitStatus::Reserved);
        RequestAllocation::factory()->holding($this->request(), $unit)->create();

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $run = AuditLog::query()->where('action', 'inventory.expiry_swept')->latest('id')->first();

        $this->assertSame(1, $run->context['released_hold_count']);
        $this->assertSame(1, $run->context['expired_count']);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $unit = $this->unit(['id' => 'STALE-2', 'expiry_date' => $this->inDays(-1)], BloodUnitStatus::Reserved);
        $hold = RequestAllocation::factory()->holding($this->request(), $unit)->create();

        $this->artisan('inventory:expire-units')->assertSuccessful();
        $cancelledAt = $hold->fresh()->cancelled_at;

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $this->assertEquals($cancelledAt, $hold->fresh()->cancelled_at);
        $this->assertSame(BloodUnitStatus::Expired, $unit->fresh()->status);
    }

    private function request(): BloodRequest
    {
        return BloodRequest::factory()
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, 2)
            ->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function unit(array $overrides, BloodUnitStatus $status): BloodUnit
    {
        $donation = Donation::factory()->create([
            'facility_id' => $this->centre->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        return BloodUnit::factory()->create([
            'facility_id' => $this->centre->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => $status,
            ...$overrides,
        ]);
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
