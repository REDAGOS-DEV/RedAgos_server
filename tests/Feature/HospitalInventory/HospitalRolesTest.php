<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalRole;
use App\Models\User;
use App\Support\HospitalPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * A hospital blood bank's roles (project owner, 2026-10-11).
 *
 * What is locked in: requesting staff raise requests and confirm dispatched
 * blood arrived, and never touch the shelf; inventory staff stock bags in and
 * handle them, and raise no request; both read requests and stock; the Head
 * holds every hospital ability, and the client is told so; and an account
 * with no hospital role reaches nothing but its notifications.
 */
class HospitalRolesTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    private User $inventory;

    private User $requesting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00'));

        $this->inventory = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::InventoryStaff)->create();
        $this->requesting = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::RequestingStaff)->create();
    }

    public function test_requesting_staff_confirm_arrival_and_inventory_staff_stock_it_in(): void
    {
        $request = $this->releasedRequest(1);
        $unitId = $request->allocations()->value('unit_id');

        // Inventory staff do not assert the delivery arrived; requesting staff do.
        $this->actingAs($this->inventory)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertForbidden();
        $this->actingAs($this->requesting)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk();

        // Requesting staff do not stock the shelf; inventory staff do.
        $stockIn = ['units' => [['unit_id' => $unitId, 'storage_location' => 'Ref 1']]];
        $this->actingAs($this->requesting)->postJson('/api/hospital/inventory/stock-in', $stockIn)->assertForbidden();
        $this->actingAs($this->inventory)->postJson('/api/hospital/inventory/stock-in', $stockIn)->assertOk();

        $this->actingAs($this->inventory)
            ->postJson("/api/hospital/inventory/{$unitId}/tag", $this->tagPayload())
            ->assertOk();
    }

    public function test_requesting_staff_never_touch_the_shelf(): void
    {
        $unit = $this->receiveOne();

        $this->actingAs($this->requesting)->postJson("/api/hospital/inventory/{$unit->unit_id}/tag", $this->tagPayload())->assertForbidden();
        $this->actingAs($this->requesting)->postJson("/api/hospital/inventory/{$unit->unit_id}/discard", ['reason' => 'Damaged'])->assertForbidden();
        $this->actingAs($this->requesting)->putJson('/api/hospital/inventory/thresholds', ['thresholds' => []])->assertForbidden();
        $this->actingAs($this->requesting)->postJson('/api/hospital/direct-distributions', [])->assertForbidden();

        // They still read the stock.
        $this->actingAs($this->requesting)->getJson('/api/hospital/inventory')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_inventory_staff_raise_no_request_and_read_no_bill(): void
    {
        $request = $this->releasedRequest(1);

        $this->actingAs($this->inventory)->postJson('/api/hospital/transfusion-requests', [])->assertForbidden();
        $this->actingAs($this->inventory)->postJson('/api/hospital/weekly-requests', [])->assertForbidden();
        $this->actingAs($this->inventory)->postJson("/api/hospital/blood-requests/{$request->id}/cancel", [])->assertForbidden();
        $this->actingAs($this->inventory)->getJson("/api/hospital/blood-requests/{$request->id}/billing")->assertForbidden();
        $this->actingAs($this->inventory)->getJson('/api/hospital/staff')->assertForbidden();

        // They still read the requests.
        $this->actingAs($this->inventory)->getJson('/api/hospital/blood-requests')->assertOk();
        $this->actingAs($this->inventory)->getJson("/api/hospital/blood-requests/{$request->id}")->assertOk();
    }

    public function test_the_head_holds_every_hospital_ability_and_the_client_is_told(): void
    {
        $permissions = $this->actingAs($this->requester)->getJson('/api/user')->assertOk()
            ->assertJsonPath('data.hospital_role', HospitalRole::Head->value)
            ->assertJsonPath('data.hospital_role_label', 'Blood Bank Head')
            ->json('data.permissions');

        foreach (HospitalPermissions::all() as $ability) {
            $this->assertContains($ability, $permissions);
        }

        $narrow = $this->actingAs($this->inventory)->getJson('/api/user')->json('data.permissions');
        $this->assertContains('hospital.inventory.stock_in', $narrow);
        $this->assertNotContains('hospital.receipt.confirm', $narrow);
        $this->assertNotContains('hospital.staff.manage', $narrow);
    }

    public function test_an_account_without_a_hospital_role_reaches_only_its_notifications(): void
    {
        $none = User::factory()->bloodBankStaff($this->hospital)->create(['hospital_role' => null]);

        $this->actingAs($none)->getJson('/api/hospital/inventory')->assertForbidden();
        $this->actingAs($none)->getJson('/api/hospital/blood-requests')->assertForbidden();
        $this->actingAs($none)->getJson('/api/hospital/notifications')->assertOk();
    }

    public function test_a_blood_centre_account_holds_no_hospital_ability(): void
    {
        $this->assertSame([], array_values(array_filter(
            $this->issuance->abilities(),
            fn (string $ability): bool => str_starts_with($ability, 'hospital.')
        )));
    }
}
