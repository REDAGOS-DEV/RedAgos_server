<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalRole;
use App\Enums\HospitalUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\Facility;
use App\Models\HospitalUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * A bag on the hospital's shelf reserved for the blood donor partner program.
 *
 * Project owner, 2026-11-07: reserved from Blood Bank Inventory, printed
 * purple on the Inventory Report. Since 2026-11-13 it is a status of its own,
 * Reserved for Donor Partner: kept for the program alone, never tagged to a
 * patient, and counted in no available figure until it is released. No
 * partner is named.
 */
class HospitalPartnerReservationTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00')); // 10:00 Manila
    }

    private function reserve(HospitalUnit $unit, ?User $as = null, array $payload = []): TestResponse
    {
        return $this->actingAs($as ?? $this->requester)
            ->postJson("/api/hospital/inventory/{$unit->unit_id}/reserve", $payload);
    }

    public function test_reserving_a_bag_moves_it_to_reserved_for_donor_partner(): void
    {
        $unit = $this->receiveOne();

        // A partner name, if a client still sends one, is neither needed nor kept.
        $this->reserve($unit, payload: ['partner' => 'Davao Rotary Club'])
            ->assertOk()
            ->assertJsonPath('message', 'Blood unit reserved for the blood donor partner program.')
            ->assertJsonPath('unit.status', HospitalUnitStatus::PartnerReserved->value)
            ->assertJsonPath('unit.status_label', 'Reserved for Donor Partner')
            ->assertJsonPath('unit.partner_reservation.reserved_at', '2026-10-02T02:00:00+00:00')
            ->assertJsonPath('unit.partner_reservation.reserved_by', $this->requester->signatureName())
            ->assertJsonMissingPath('unit.partner_reservation.partner');

        $fresh = $unit->fresh();
        $this->assertSame(HospitalUnitStatus::PartnerReserved, $fresh->status);
        $this->assertNull($fresh->partner_name);
        $this->assertSame($this->requester->id, $fresh->partner_reserved_by);

        $entry = AuditLog::query()->where('action', 'hospital_inventory.partner_reserved')->sole();
        $this->assertSame($unit->unit_id, $entry->auditable_id);
        $this->assertSame('available', $entry->context['previous_status']);
        $this->assertSame('partner_reserved', $entry->context['new_status']);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/activity')
            ->assertJsonPath('data.0.kind', 'reserved');
    }

    public function test_only_an_untagged_bag_in_date_on_the_shelf_may_be_reserved(): void
    {
        [$tagged, $reserved] = $this->receiveStock(2);
        $this->tagUnit($tagged)->assertOk();
        $this->reserve($reserved)->assertOk();

        $this->reserve($tagged)->assertStatus(409)->assertJsonPath('code', 'unit_not_available');
        $this->reserve($reserved)->assertStatus(409)->assertJsonPath('code', 'unit_already_reserved');

        [$unstocked] = $this->receiveUnstocked(1);
        $this->reserve($unstocked)->assertStatus(409)->assertJsonPath('code', 'unit_not_available');

        // Past its date, still Available until the expiry sweep runs.
        $lapsing = $this->receiveOne(expiresInDays: 2);
        $this->travel(4)->days();
        $this->reserve($lapsing)->assertStatus(409)->assertJsonPath('code', 'bag_expired');
    }

    public function test_a_reserved_bag_cannot_be_tagged_to_a_patient(): void
    {
        $unit = $this->receiveOne();
        $this->reserve($unit)->assertOk();

        $this->tagUnit($unit)
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_available')
            ->assertJsonPath('message', 'This bag is reserved for the blood donor partner program. Release the reservation before tagging it to a patient.');

        $this->assertSame(HospitalUnitStatus::PartnerReserved, $unit->fresh()->status);
        $this->assertSame(0, DB::table('unit_tags')->count());
    }

    public function test_a_reserved_bag_counts_in_no_available_figure(): void
    {
        // Ten bags of one type and component, three of them reserved.
        $bags = $this->receiveStock(10);
        foreach ($bags->take(3) as $bag) {
            $this->reserve($bag)->assertOk();
        }

        $summary = $this->actingAs($this->requester)->getJson('/api/hospital/inventory/summary')->assertOk();
        $this->assertSame(7, $summary->json('totals.available'));
        $this->assertSame(3, $summary->json('totals.partner_reserved'));
        $this->assertSame(7, $summary->json('by_blood_type.0.available'));

        $this->actingAs($this->requester)->getJson('/api/hospital/dashboard')
            ->assertOk()
            ->assertJsonPath('units_available', 7);

        // The live stock grid, which thresholds and alerts judge.
        $cells = $this->actingAs($this->requester)->getJson('/api/hospital/inventory/thresholds')->assertOk()->json('cells');
        $this->assertSame(7, collect($cells)->sum('available'));

        // Listed under Available only once released.
        $available = fn (): array => collect($this->actingAs($this->requester)->getJson('/api/hospital/inventory?status=available')->json('data'))
            ->pluck('unit_id')->all();
        $this->assertNotContains($bags[0]->unit_id, $available());

        $this->act($bags[0], 'unreserve')->assertOk();
        $this->assertContains($bags[0]->unit_id, $available());
    }

    public function test_releasing_the_reservation_returns_the_bag_to_the_shelf(): void
    {
        $unit = $this->receiveOne();
        $this->reserve($unit)->assertOk();

        $this->act($unit, 'unreserve')
            ->assertOk()
            ->assertJsonPath('message', 'Reservation released. The bag is available again.')
            ->assertJsonPath('unit.status', HospitalUnitStatus::Available->value)
            ->assertJsonPath('unit.partner_reservation', null);

        $fresh = $unit->fresh();
        $this->assertNull($fresh->partner_reserved_at);
        $this->assertNull($fresh->partner_reserved_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'hospital_inventory.partner_released')->count());

        $this->act($unit, 'unreserve')->assertStatus(409)->assertJsonPath('code', 'unit_not_reserved');

        // Free to tag to a patient again.
        $this->tagUnit($unit)->assertOk()->assertJsonPath('unit.status', HospitalUnitStatus::TagAssigned->value);
    }

    public function test_a_reserved_bag_still_expires_and_may_be_discarded(): void
    {
        [$lapsing, $damaged] = $this->receiveStock(2, expiresInDays: 2);
        $this->reserve($lapsing)->assertOk();
        $this->reserve($damaged)->assertOk();

        $this->act($damaged, 'discard', ['reason' => 'Bag leaking'])
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Discarded->value);

        $this->travel(4)->days();
        $this->artisan('hospital:expire-units')->assertSuccessful();

        $this->assertSame(HospitalUnitStatus::Expired, $lapsing->fresh()->status);
    }

    public function test_the_status_filter_lists_reserved_bags(): void
    {
        [$free, $reserved] = $this->receiveStock(2);
        $this->reserve($reserved)->assertOk();

        foreach (['partner_reserved', 'reserved'] as $filter) {
            $this->actingAs($this->requester)
                ->getJson("/api/hospital/inventory?status={$filter}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.unit_id', $reserved->unit_id);
        }
    }

    public function test_the_inventory_report_prints_a_reserved_bag_purple_and_a_tagged_one_pink(): void
    {
        Storage::fake('local');
        $this->prbc->update(['code' => 'PRBC']);
        BloodComponent::factory()->create(['name' => 'Whole Blood', 'code' => 'WB', 'price' => 0]);

        [$free, $reserved, $tagged] = $this->receiveStock(3);
        $this->reserve($reserved)->assertOk();
        $this->tagUnit($tagged)->assertOk();

        $sheet = $this->actingAs($this->requester)
            ->postJson('/api/hospital/inventory/reports', ['by' => 'type'])
            ->assertCreated()
            ->json('sheet');

        $cells = collect($sheet['sheets'][0]['uncrossmatched'][1]['cells'])->filter()->keyBy('bag_number');
        $this->assertFalse($cells[$free->unit_id]['reserved']);
        $this->assertTrue($cells[$reserved->unit_id]['reserved']);
        $this->assertFalse($cells[$tagged->unit_id]['reserved']);
        $this->assertTrue($cells[$tagged->unit_id]['tagged']);

        $html = view('pdf.hospital-inventory-sheet', ['sheet' => $sheet])->render();
        $cell = fn (string $bag, string $class, string $mark): string => '/<td class="bag'.$class.'">'.preg_quote($bag, '/').$mark.'<\/td>\s*<td class="exp'.$class.'">/';

        $this->assertMatchesRegularExpression($cell($free->unit_id, '', ''), $html);
        $this->assertMatchesRegularExpression($cell($reserved->unit_id, ' reserved', ' \(R\)'), $html);
        $this->assertMatchesRegularExpression($cell($tagged->unit_id, ' tagged', ' \(T\)'), $html);
        $this->assertStringContainsString('.reserved, .legend .key.reserved { color: #7B1FA2; }', $html);
    }

    public function test_requesting_staff_cannot_reserve_and_another_hospital_cannot_reach_the_bag(): void
    {
        $unit = $this->receiveOne();

        $requesting = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::RequestingStaff)->create();
        $this->reserve($unit, $requesting)->assertForbidden();
        $this->actingAs($requesting)->postJson("/api/hospital/inventory/{$unit->unit_id}/unreserve")->assertForbidden();

        $inventory = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::InventoryStaff)->create();
        $this->reserve($unit, $inventory)->assertOk();

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $otherStaff = User::factory()->bloodBankStaff($otherHospital)->create();
        $this->actingAs($otherStaff)->postJson("/api/hospital/inventory/{$unit->unit_id}/unreserve")->assertNotFound();

        $this->assertSame(HospitalUnitStatus::PartnerReserved, $unit->fresh()->status);
    }

    public function test_the_migration_turns_the_old_mark_into_the_status(): void
    {
        // No tags: SQLite rebuilds the table to widen the status column, and
        // inside the test's transaction it cannot set aside unit_tags' key.
        [$marked, $markedThenExpired] = $this->receiveStock(2);

        // The old mark: an available bag carrying a partner name. The other
        // carried it until the bag left the shelf.
        DB::table('hospital_units')->whereIn('id', [$marked->id, $markedThenExpired->id])
            ->update(['partner_name' => 'Davao Rotary Club', 'partner_reserved_at' => now(), 'partner_reserved_by' => $this->requester->id]);
        DB::table('hospital_units')->where('id', $markedThenExpired->id)->update(['status' => 'expired']);

        $migration = require database_path('migrations/2026_11_13_000002_add_partner_reserved_hospital_unit_status.php');
        $migration->up();

        $this->assertSame(HospitalUnitStatus::PartnerReserved, $marked->fresh()->status);
        $this->assertNull($marked->fresh()->partner_name);
        $this->assertNotNull($marked->fresh()->partner_reserved_at);

        // A bag no longer on the shelf keeps its status and loses the mark.
        $this->assertSame(HospitalUnitStatus::Expired, $markedThenExpired->fresh()->status);
        $this->assertNull($markedThenExpired->fresh()->partner_name);
        $this->assertNull($markedThenExpired->fresh()->partner_reserved_at);
    }
}
