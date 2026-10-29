<?php

namespace Tests\Feature\HospitalInventory;

use App\Models\Facility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * The Blood Bank Inventory's Recent Activity: what happened to the hospital's bags, newest first.
 */
class HospitalInventoryActivityTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00')); // 10:00 Manila
    }

    public function test_the_hospitals_own_steps_are_listed_newest_first_with_who_took_them(): void
    {
        $unit = $this->receiveOne();
        $this->travel(1)->minutes();
        $this->tagUnit($unit)->assertOk();
        $this->travel(1)->minutes();
        $this->act($unit, 'crossmatch')->assertOk();

        $staff = $this->requester->signatureName();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/activity')
            ->assertOk()
            // Received, stocked, tagged, crossmatched — and nothing of the
            // centre's own history of the bag (booked in, reserved, issued).
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.kind', 'crossmatched')
            ->assertJsonPath('data.0.unit_id', $unit->unit_id)
            ->assertJsonPath('data.0.bag_number', $unit->unit_id)
            ->assertJsonPath('data.0.blood_type', 'O+')
            ->assertJsonPath('data.0.component', 'Packed RBC')
            ->assertJsonPath('data.0.actor', $staff)
            ->assertJsonPath('data.1.kind', 'tagged')
            ->assertJsonPath('data.2.kind', 'stocked')
            ->assertJsonPath('data.3.kind', 'received');
    }

    public function test_one_bags_history_is_its_own(): void
    {
        [$mine, $other] = $this->receiveStock(2);
        $this->tagUnit($other)->assertOk();

        $kinds = $this->actingAs($this->requester)
            ->getJson("/api/hospital/inventory/activity?unit_id={$mine->unit_id}")
            ->assertOk()
            ->json('data.*.kind');

        $this->assertSame(['stocked', 'received'], $kinds);
    }

    public function test_another_hospital_sees_none_of_it(): void
    {
        $this->receiveOne();

        $elsewhere = User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->create();

        $this->actingAs($elsewhere)
            ->getJson('/api/hospital/inventory/activity')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
