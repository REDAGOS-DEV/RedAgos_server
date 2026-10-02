<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\BloodUnitStatus;
use App\Enums\HospitalUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\HospitalUnit;
use App\Models\RequestAllocation;
use App\Repository\InventoryRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * Receipt is how a bag reaches a hospital blood bank's own shelf — and the only way.
 *
 * The centre's side of the bag must not move: it was dispatched, it reads
 * `issued`, and the hospital's custody is a separate row beside it. A change
 * meant for the hospital that leaks into the centre's counts breaks the one
 * rule the specification calls out by name.
 */
class HospitalStockReceiptTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_confirming_receipt_puts_each_bag_on_the_hospital_shelf_as_available(): void
    {
        $request = $this->releasedRequest(2);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('received_count', 2)
            ->assertJsonPath('stocked_count', 2);

        $stocked = HospitalUnit::query()->orderBy('unit_id')->get();

        $this->assertCount(2, $stocked);
        $this->assertEqualsCanonicalizing(
            $request->allocations()->pluck('unit_id')->all(),
            $stocked->pluck('unit_id')->all()
        );

        foreach ($stocked as $unit) {
            $this->assertSame(HospitalUnitStatus::Available, $unit->status);
            $this->assertSame($this->hospital->id, $unit->facility_id);
            $this->assertSame($unit->unit_id, $unit->requestAllocation->unit_id);
        }
    }

    public function test_a_partial_receipt_stocks_only_the_bags_confirmed(): void
    {
        $request = $this->releasedRequest(2);
        $first = $request->allocations()->orderBy('id')->first();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt", ['allocation_ids' => [$first->id]])
            ->assertOk()
            ->assertJsonPath('stocked_count', 1);

        $this->assertSame([$first->unit_id], HospitalUnit::query()->pluck('unit_id')->all());
    }

    public function test_a_second_receipt_is_refused_and_stocks_nothing_twice(): void
    {
        $request = $this->releasedRequest(2);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_confirm');

        $this->assertSame(2, HospitalUnit::query()->count());
    }

    public function test_a_patient_transfusion_allocation_stocks_like_any_other_request(): void
    {
        $this->stock($this->centre, $this->prbc, 1);
        $requirement = $this->recordTransfusion([[$this->prbc, 1]], [[$this->centre, [[$this->prbc, 1]]]]);
        $allocation = $this->allocationAt($requirement, $this->centre);

        $this->allocateAndRelease($allocation);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$allocation->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('stocked_count', 1);

        // The bag remembers which patient's requirement it was received for,
        // which is what lets the tag form suggest that patient.
        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.source.transfusion_request_id', $requirement->id)
            ->assertJsonPath('data.0.source.transfusion_reference', $requirement->reference_number)
            ->assertJsonPath('data.0.source.reference_number', $allocation->reference_number);
    }

    public function test_bags_received_before_hospital_stock_existed_are_not_backfilled(): void
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->withComponents([[$this->prbc, 1]])
            ->create();

        RequestAllocation::factory()
            ->holding($request, BloodUnit::factory()->issued()->create([
                'facility_id' => $this->centre->id,
                'blood_type_id' => $this->bloodType->id,
                'component_id' => $this->prbc->id,
            ]))
            ->received($this->requester)
            ->create();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, HospitalUnit::query()->count());
    }

    public function test_the_centre_side_of_the_bag_is_left_exactly_as_dispatch_wrote_it(): void
    {
        $request = $this->releasedRequest(1);
        $bag = BloodUnit::query()->findOrFail($request->allocations()->value('unit_id'));
        $centreCounts = app(InventoryRepository::class)->summaryCounts($this->centre->id);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk();

        $unit = HospitalUnit::query()->where('unit_id', $bag->id)->firstOrFail();

        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();
        $this->act($unit, 'transfuse')->assertOk();

        $after = $bag->fresh();

        $this->assertSame(BloodUnitStatus::Issued, $after->status);
        $this->assertSame($this->centre->id, $after->facility_id);
        $this->assertSame($bag->expiry_date->toDateString(), $after->expiry_date->toDateString());
        $this->assertEquals($bag->updated_at, $after->updated_at, 'Nothing at the hospital may write to the centre\'s row.');
        $this->assertSame($centreCounts, app(InventoryRepository::class)->summaryCounts($this->centre->id));
    }

    public function test_each_stocked_bag_is_audited_against_the_bag_itself(): void
    {
        $request = $this->releasedRequest(2);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk();

        $rows = AuditLog::query()->where('action', 'hospital_inventory.stocked')->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            $request->allocations()->pluck('unit_id')->all(),
            $rows->pluck('auditable_id')->all()
        );
        $this->assertSame(BloodUnit::class, $rows->first()->auditable_type);
        $this->assertSame($this->requester->id, $rows->first()->actor_id);
        $this->assertSame($this->hospital->id, $rows->first()->context['facility_id']);
    }
}
