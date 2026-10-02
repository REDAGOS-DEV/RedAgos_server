<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * What hospital staff see: their shelf, its tags, and the tags that ended.
 */
class HospitalInventoryListingTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00')); // 10:00 Manila
    }

    public function test_the_shelf_is_listed_first_expiring_first(): void
    {
        $late = $this->receiveOne(expiresInDays: 20);
        $soon = $this->receiveOne(expiresInDays: 3);
        $middle = $this->receiveOne(expiresInDays: 9);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.unit_id', $soon->unit_id)
            ->assertJsonPath('data.0.days_remaining', 3)
            ->assertJsonPath('data.1.unit_id', $middle->unit_id)
            ->assertJsonPath('data.2.unit_id', $late->unit_id)
            ->assertJsonPath('data.0.blood_type.code', 'O+')
            ->assertJsonPath('data.0.component.name', 'Packed RBC')
            ->assertJsonPath('data.0.bag_expired', false);
    }

    public function test_the_list_filters_by_status_and_finds_a_bag_or_a_patient(): void
    {
        [$tagged, $free] = $this->receiveStock(2);
        $this->tagUnit($tagged, ['patient_surname' => 'Villanueva'])->assertOk();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?status=tag_assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_id', $tagged->unit_id)
            ->assertJsonPath('data.0.active_tag.patient.surname', 'Villanueva');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?search=villa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_id', $tagged->unit_id);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?search='.$free->unit_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_id', $free->unit_id)
            ->assertJsonPath('data.0.active_tag', null);
    }

    public function test_a_patients_bags_are_those_received_for_them_and_those_tagged_to_them(): void
    {
        $this->stock($this->centre, $this->prbc, 1);
        $requirement = $this->recordTransfusion([[$this->prbc, 1]], [[$this->centre, [[$this->prbc, 1]]]]);
        $allocation = $this->allocationAt($requirement, $this->centre);
        $this->allocateAndRelease($allocation);
        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$allocation->id}/confirm-receipt")
            ->assertOk();

        [$ownShelf, $unrelated] = $this->receiveStock(2);
        $this->tagUnit($ownShelf, ['transfusion_request_id' => $requirement->id])->assertOk();

        $listed = $this->actingAs($this->requester)
            ->getJson("/api/hospital/inventory?transfusion_request_id={$requirement->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $listed);
        $this->assertContains($ownShelf->unit_id, array_column($listed, 'unit_id'));
        $this->assertNotContains($unrelated->unit_id, array_column($listed, 'unit_id'));
    }

    public function test_an_unknown_status_filter_is_refused(): void
    {
        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?status=reserved')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_the_summary_counts_every_status_from_the_bags(): void
    {
        [$tagged] = $this->receiveStock(2);
        $this->receiveOne(expiresInDays: 2);
        $this->tagUnit($tagged)->assertOk();

        $response = $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/summary')
            ->assertOk()
            ->assertJsonPath('totals.available', 2)
            ->assertJsonPath('totals.tag_assigned', 1)
            ->assertJsonPath('by_blood_type.0.code', 'O+')
            ->assertJsonPath('by_blood_type.0.available', 2)
            ->assertJsonPath('near_expiry.within_3_days', 1)
            ->assertJsonPath('overdue_active_tags', 0);

        $this->assertSame(HospitalUnitStatus::values(), array_keys($response->json('totals')));
    }

    public function test_tag_events_list_the_tags_that_ended_newest_first(): void
    {
        [$released, $transfused, $active] = $this->receiveStock(3);

        $this->tagUnit($released)->assertOk();
        $this->tagUnit($transfused, ['patient_surname' => 'Reyes'])->assertOk();
        $this->tagUnit($active)->assertOk();

        $this->travel(1)->hours();
        $this->act($released, 'release', ['reason' => 'Patient discharged'])->assertOk();

        $this->travel(1)->hours();
        $this->act($transfused, 'crossmatch')->assertOk();
        $this->act($transfused, 'transfuse')->assertOk();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/tag-events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', UnitTagStatus::Transfused->value)
            ->assertJsonPath('data.0.unit.unit_id', $transfused->unit_id)
            ->assertJsonPath('data.1.status', UnitTagStatus::UntaggedAssigned->value)
            ->assertJsonPath('data.1.untag_reason', UntagReason::ReleasedByStaff->value)
            ->assertJsonPath('data.1.untag_note', 'Patient discharged');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/tag-events?status=untagged_assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/tag-events?search=reyes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient.surname', 'Reyes');
    }

    public function test_one_bag_shows_its_whole_tag_history(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'release', ['reason' => 'Order cancelled'])->assertOk();
        $this->tagUnit($unit, ['patient_surname' => 'Reyes'])->assertOk();

        $staff = trim($this->requester->first_name.' '.$this->requester->last_name);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/inventory/{$unit->unit_id}")
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::TagAssigned->value)
            ->assertJsonPath('unit.active_tag.patient.surname', 'Reyes')
            ->assertJsonCount(2, 'tags')
            ->assertJsonPath('tags.0.status', UnitTagStatus::TagAssigned->value)
            ->assertJsonPath('tags.1.status', UnitTagStatus::UntaggedAssigned->value)
            ->assertJsonPath('tags.1.actors.tagged_by', $staff)
            ->assertJsonPath('tags.1.actors.untagged_by', $staff)
            ->assertJsonPath('tags.1.untagged_by_system', false);
    }

    public function test_reference_data_serves_the_hospital_stock_vocabulary_from_the_enums(): void
    {
        $response = $this->actingAs($this->requester)
            ->getJson('/api/hospital/reference-data')
            ->assertOk();

        $this->assertSame(HospitalUnitStatus::values(), array_column($response->json('inventory_statuses'), 'value'));
        $this->assertSame(UnitTagStatus::values(), array_column($response->json('tag_statuses'), 'value'));
        $this->assertSame(UntagReason::values(), array_column($response->json('untag_reasons'), 'value'));

        $response
            ->assertJsonPath('tag_statuses.0.label', 'Tag Assigned')
            ->assertJsonPath('tag_statuses.0.description', 'Crossmatch pending')
            ->assertJsonPath('tag_statuses.1.description', 'Awaiting transfusion');
    }
}
