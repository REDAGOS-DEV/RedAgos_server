<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\AllocationStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\HospitalRole;
use App\Enums\HospitalUnitStatus;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\HospitalUnit;
use App\Models\RequestAllocation;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Notifications\TransfusionSuppliedByHospital;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * A hospital blood bank supplying another hospital's Blood Request from its own near-expiry stock (project owner, 2026-11-28).
 *
 * The scenario's hospital (Southern) holds bags; a second hospital (Northern)
 * raises a Blood Request a centre was asked only part of. Southern sees the
 * request without the patient, sends near-expiry bags in one step, nothing
 * is billed, and Northern receives them through its own Receive and Stock
 * Intake.
 */
class HospitalSupplyTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    private Facility $northern;

    private User $northernStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        $this->northern = Facility::factory()->bloodBank()->approved()->create(['name' => 'Northern Hospital Blood Bank']);
        $this->northernStaff = User::factory()->bloodBankStaff($this->northern)->create();
    }

    public function test_a_hospital_sees_other_hospitals_open_requests_without_the_patient(): void
    {
        $this->receiveStock(2, null, 3);
        $theirs = $this->northernRequest();
        $this->recordTransfusion([[$this->prbc, 1]], [[$this->centre, [[$this->prbc, 1]]]]);

        $row = $this->actingAs($this->requester)
            ->getJson('/api/hospital/supply-requests')
            ->assertOk()
            // Its own request is not listed.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_number', $theirs->reference_number)
            ->assertJsonPath('data.0.hospital.name', 'Northern Hospital Blood Bank')
            ->assertJsonPath('data.0.blood_type.code', 'O+')
            ->assertJsonPath('data.0.lines.0.required', 3)
            ->assertJsonPath('data.0.lines.0.unallocated', 2)
            ->assertJsonPath('data.0.lines.0.near_expiry_here', 2)
            ->assertJsonPath('data.0.sendable_quantity', 2)
            ->assertJsonPath('data.0.can_supply', true)
            ->json('data.0');

        $this->assertArrayNotHasKey('patient', $row);
        $this->assertStringNotContainsString('Dela Cruz', json_encode($row));

        // The patient's name finds nothing.
        $this->actingAs($this->requester)
            ->getJson('/api/hospital/supply-requests?search=dela')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_only_near_expiry_bags_are_offered_and_sent(): void
    {
        [$soon] = $this->receiveStock(1, null, 3)->all();
        [$fresh] = $this->receiveStock(1, null, 20)->all();
        $theirs = $this->northernRequest();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/supply-requests/{$theirs->id}/units")
            ->assertOk()
            ->assertJsonCount(1, 'lines.0.units')
            ->assertJsonPath('lines.0.units.0.unit_id', $soon->unit_id)
            ->assertJsonPath('window_days', 7);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/supply-requests/{$theirs->id}/supply", ['unit_ids' => [$fresh->unit_id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_near_expiry');

        $this->assertSame(HospitalUnitStatus::Available, $fresh->fresh()->status);
    }

    public function test_a_hospital_sends_bags_and_the_other_hospital_receives_them(): void
    {
        Notification::fake();
        $bags = $this->receiveStock(2, null, 3);
        $theirs = $this->northernRequest();
        $broughtBy = $bags->pluck('request_allocation_id')->all();

        $response = $this->actingAs($this->requester)
            ->postJson("/api/hospital/supply-requests/{$theirs->id}/supply", ['unit_ids' => $bags->pluck('unit_id')->all()])
            ->assertCreated()
            ->assertJsonPath('request.unallocated_quantity', 0)
            ->assertJsonPath('request.can_supply', false)
            ->assertJsonPath('request.suppliers.1.name', 'Southern Hospital Blood Bank')
            ->assertJsonPath('request.suppliers.1.is_mine', true);

        $share = BloodRequest::query()->with('allocations')->findOrFail($response->json('share.id'));

        // A share of Northern's request, addressed to Southern, sent at once.
        $this->assertSame($this->hospital->id, (int) $share->target_facility_id);
        $this->assertSame($this->northern->id, (int) $share->facility_id);
        $this->assertSame($theirs->id, (int) $share->transfusion_request_id);
        $this->assertSame(BloodRequestStatus::Fulfilled, $share->status);
        $this->assertSame(2, $share->quantity);
        $this->assertCount(2, $share->allocations);
        $this->assertTrue($share->allocations->every(fn (RequestAllocation $hold): bool => $hold->status === AllocationStatus::Released && $hold->received_at === null));

        // Gone from Southern's shelf; the holds that brought them there are passed on.
        foreach ($bags as $bag) {
            $bag->refresh();
            $this->assertSame(HospitalUnitStatus::Transferred, $bag->status);
            $this->assertSame($this->requester->id, (int) $bag->transferred_by);
            $this->assertNotNull($bag->transfer_allocation_id);
        }
        $this->assertSame(2, RequestAllocation::query()->whereIn('id', $broughtBy)->whereNotNull('passed_on_at')->count());

        // Nothing is billed.
        $this->assertDatabaseMissing('billings', ['request_id' => $share->id]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'request.hospital_supplied']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hospital_inventory.transferred']);
        Notification::assertSentTo($this->northernStaff, TransfusionSuppliedByHospital::class);

        // Northern's own view names it as sent by a hospital, not added by a centre.
        $shares = collect($this->actingAs($this->northernStaff)
            ->getJson("/api/hospital/transfusion-requests/{$theirs->id}")
            ->assertOk()
            ->json('request.allocations'));
        $this->assertTrue($shares->firstWhere('id', $share->id)['supplied_by_hospital']);
        $this->assertFalse($shares->firstWhere('id', '!=', $share->id)['supplied_by_hospital']);

        // Northern receives them through its own Receive and Stock Intake.
        $this->actingAs($this->northernStaff)
            ->postJson("/api/hospital/blood-requests/{$share->id}/confirm-receipt")
            ->assertOk();

        $this->actingAs($this->northernStaff)
            ->postJson('/api/hospital/inventory/stock-in', [
                'units' => $bags->map(fn (HospitalUnit $bag): array => ['unit_id' => $bag->unit_id, 'storage_location' => 'Ref 2'])->values()->all(),
            ])
            ->assertOk();

        foreach ($bags as $bag) {
            $this->assertSame(
                HospitalUnitStatus::Available,
                HospitalUnit::query()->where('facility_id', $this->northern->id)->where('unit_id', $bag->unit_id)->sole()->status
            );
        }

        // Two of three released; the centre still holds the third.
        $this->assertSame(BloodRequestStatus::Partial, $theirs->fresh()->status);
    }

    public function test_it_never_sends_more_than_is_unallocated_or_the_wrong_blood(): void
    {
        $bags = $this->receiveStock(3, null, 3);
        $theirs = $this->northernRequest();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/supply-requests/{$theirs->id}/supply", ['unit_ids' => $bags->pluck('unit_id')->all()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'too_many_units');

        [$plasma] = $this->receiveStock(1, $this->ffp, 3)->all();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/supply-requests/{$theirs->id}/supply", ['unit_ids' => [$plasma->unit_id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_wrong_component');

        $this->assertSame(0, HospitalUnit::query()->where('status', HospitalUnitStatus::Transferred->value)->count());
    }

    public function test_a_hospital_cannot_supply_its_own_request(): void
    {
        [$bag] = $this->receiveStock(1, null, 3)->all();
        $own = $this->recordTransfusion([[$this->prbc, 3]], [[$this->centre, [[$this->prbc, 1]]]]);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/supply-requests/{$own->id}/supply", ['unit_ids' => [$bag->unit_id]])
            ->assertNotFound();
    }

    public function test_requesting_staff_may_read_but_not_send(): void
    {
        [$bag] = $this->receiveStock(1, null, 3)->all();
        $theirs = $this->northernRequest();
        $requesting = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::RequestingStaff)->create();

        $this->actingAs($requesting)->getJson('/api/hospital/supply-requests')->assertOk();
        $this->actingAs($requesting)
            ->postJson("/api/hospital/supply-requests/{$theirs->id}/supply", ['unit_ids' => [$bag->unit_id]])
            ->assertForbidden();

        $inventory = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::InventoryStaff)->create();
        $this->actingAs($inventory)
            ->getJson("/api/hospital/supply-requests/{$theirs->id}/units")
            ->assertOk();
    }

    /**
     * Northern's Blood Request: PRBC 3, of which the centre was asked for 1.
     */
    private function northernRequest(): TransfusionRequest
    {
        $id = $this->actingAs($this->northernStaff)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 3]],
                [[$this->centre, [[$this->prbc, 1]]]],
            ))
            ->assertCreated()
            ->json('request.id');

        return TransfusionRequest::query()->findOrFail($id);
    }
}
