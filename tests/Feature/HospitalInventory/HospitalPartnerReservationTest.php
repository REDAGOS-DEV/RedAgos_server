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
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * A bag on the hospital's shelf reserved for a blood donor partner (project
 * owner, 2026-11-07): set from Blood Bank Inventory, printed purple on the
 * Inventory Report.
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

    private function reserve(HospitalUnit $unit, string $partner = 'Davao Rotary Club', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->requester)
            ->postJson("/api/hospital/inventory/{$unit->unit_id}/reserve", ['partner' => $partner]);
    }

    public function test_a_bag_on_the_shelf_is_reserved_for_the_partner_and_stays_available(): void
    {
        $unit = $this->receiveOne();

        $this->reserve($unit, '  Davao Rotary Club  ')
            ->assertOk()
            ->assertJsonPath('message', 'Blood unit reserved for the blood donor partner.')
            ->assertJsonPath('unit.status', HospitalUnitStatus::Available->value)
            ->assertJsonPath('unit.partner_reservation.partner', 'Davao Rotary Club')
            ->assertJsonPath('unit.partner_reservation.reserved_at', '2026-10-02T02:00:00+00:00')
            ->assertJsonPath('unit.partner_reservation.reserved_by', $this->requester->signatureName());

        $fresh = $unit->fresh();
        $this->assertSame(HospitalUnitStatus::Available, $fresh->status);
        $this->assertSame($this->requester->id, $fresh->partner_reserved_by);

        // Audited against the bag, without the partner's name.
        $entry = AuditLog::query()->where('action', 'hospital_inventory.partner_reserved')->sole();
        $this->assertSame($unit->unit_id, $entry->auditable_id);
        $this->assertStringNotContainsString('Rotary', json_encode($entry->context));

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/activity')
            ->assertJsonPath('data.0.kind', 'reserved');
    }

    public function test_only_an_untagged_bag_in_date_not_yet_reserved_may_be_reserved(): void
    {
        [$tagged, $reserved] = $this->receiveStock(2);
        $this->tagUnit($tagged)->assertOk();
        $this->reserve($reserved)->assertOk();

        $this->reserve($tagged)->assertStatus(409)->assertJsonPath('code', 'unit_not_available');
        $this->reserve($reserved, 'Another partner')->assertStatus(409)->assertJsonPath('code', 'unit_already_reserved');
        $this->assertSame('Davao Rotary Club', $reserved->fresh()->partner_name);

        [$unstocked] = $this->receiveUnstocked(1);
        $this->reserve($unstocked)->assertStatus(409)->assertJsonPath('code', 'unit_not_available');

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/inventory/{$reserved->unit_id}/reserve", ['partner' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['partner' => 'Name the blood donor partner the bag is reserved for.']);

        // Past its date, still Available until the expiry sweep runs.
        $lapsing = $this->receiveOne(expiresInDays: 2);
        $this->travel(4)->days();
        $this->reserve($lapsing)->assertStatus(409)->assertJsonPath('code', 'bag_expired');
    }

    public function test_a_reserved_bag_may_be_tagged_and_returns_to_its_partner_when_the_tag_ends(): void
    {
        $unit = $this->receiveOne();
        $this->reserve($unit)->assertOk();

        $this->tagUnit($unit)
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::TagAssigned->value)
            ->assertJsonPath('unit.partner_reservation.partner', 'Davao Rotary Club');

        $this->act($unit, 'release', ['reason' => 'Crossmatch incompatible'])
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Available->value)
            ->assertJsonPath('unit.partner_reservation.partner', 'Davao Rotary Club');
    }

    public function test_releasing_the_reservation_frees_the_bag(): void
    {
        $unit = $this->receiveOne();
        $this->reserve($unit)->assertOk();

        $this->act($unit, 'unreserve')
            ->assertOk()
            ->assertJsonPath('message', 'Reservation released.')
            ->assertJsonPath('unit.partner_reservation', null);

        $fresh = $unit->fresh();
        $this->assertNull($fresh->partner_name);
        $this->assertNull($fresh->partner_reserved_at);
        $this->assertNull($fresh->partner_reserved_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'hospital_inventory.partner_released')->count());

        $this->act($unit, 'unreserve')->assertStatus(409)->assertJsonPath('code', 'unit_not_reserved');
    }

    public function test_the_status_filter_lists_reserved_bags_on_the_shelf(): void
    {
        [$free, $reserved, $reservedThenTagged] = $this->receiveStock(3);
        $this->reserve($reserved)->assertOk();
        $this->reserve($reservedThenTagged)->assertOk();
        $this->tagUnit($reservedThenTagged)->assertOk();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?status=reserved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_id', $reserved->unit_id);

        // Reserved bags are still on the shelf, so Available lists them too.
        $available = collect($this->actingAs($this->requester)->getJson('/api/hospital/inventory?status=available')->json('data'))
            ->pluck('unit_id');
        $this->assertEqualsCanonicalizing([$free->unit_id, $reserved->unit_id], $available->all());
    }

    public function test_the_inventory_report_prints_a_reserved_bag_purple_and_a_tagged_one_pink(): void
    {
        Storage::fake('local');
        $this->prbc->update(['code' => 'PRBC']);
        BloodComponent::factory()->create(['name' => 'Whole Blood', 'code' => 'WB', 'price' => 0]);

        [$free, $reserved, $tagged] = $this->receiveStock(3);
        $this->reserve($reserved)->assertOk();
        // Reserved, then tagged to the partner's patient: the tag is what prints.
        $this->reserve($tagged, 'Another partner')->assertOk();
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
        $this->reserve($unit, as: $requesting)->assertForbidden();
        $this->actingAs($requesting)->postJson("/api/hospital/inventory/{$unit->unit_id}/unreserve")->assertForbidden();

        $inventory = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::InventoryStaff)->create();
        $this->reserve($unit, as: $inventory)->assertOk();

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $otherStaff = User::factory()->bloodBankStaff($otherHospital)->create();
        $this->actingAs($otherStaff)->postJson("/api/hospital/inventory/{$unit->unit_id}/unreserve")->assertNotFound();

        $this->assertSame('Davao Rotary Club', $unit->fresh()->partner_name);
    }
}
