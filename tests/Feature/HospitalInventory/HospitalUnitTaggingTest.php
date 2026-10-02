<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\UnitTag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * Tag Assigned → Tag Crossmatched → Transfused, and every way off that path.
 *
 * A tag is one specific bag held for one specific patient. These tests hold
 * the line on what that means: the same bag moves through every stage, it is
 * never available to a second patient while tagged, crossmatch cannot be
 * skipped, a transfused bag never comes back, and an ended tag stays on record.
 */
class HospitalUnitTaggingTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_tagging_holds_the_bag_for_the_patient_with_24_hours_to_crossmatch(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00'));
        $unit = $this->receiveOne();

        $this->tagUnit($unit)
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::TagAssigned->value)
            ->assertJsonPath('unit.active_tag.status', UnitTagStatus::TagAssigned->value)
            ->assertJsonPath('unit.active_tag.description', 'Crossmatch pending')
            ->assertJsonPath('unit.active_tag.patient.full_name', 'SANTOS, Maria Lopez')
            ->assertJsonPath('unit.active_tag.seconds_remaining', 86400)
            ->assertJsonPath('unit.active_tag.deadline_passed', false);

        $tag = UnitTag::query()->sole();

        $this->assertSame($unit->id, $tag->hospital_unit_id);
        $this->assertSame($this->requester->id, $tag->tagged_by);
        $this->assertSame('2026-10-02 08:00:00', $tag->tagged_at->toDateTimeString());
        $this->assertSame('2026-10-03 08:00:00', $tag->crossmatch_deadline_at->toDateTimeString());
        $this->assertSame(HospitalUnitStatus::TagAssigned, $unit->fresh()->status);
    }

    public function test_a_tagged_bag_is_not_available_to_another_patient(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->tagUnit($unit, ['patient_surname' => 'Reyes', 'patient_first_name' => 'Ana'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_available');

        $this->assertSame(1, UnitTag::query()->count());
    }

    public function test_the_patient_is_required_unless_a_transfusion_request_is_linked(): void
    {
        $unit = $this->receiveOne();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/inventory/{$unit->unit_id}/tag", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_surname', 'patient_first_name', 'patient_age', 'patient_sex']);

        $this->assertSame(HospitalUnitStatus::Available, $unit->fresh()->status);
    }

    public function test_linking_the_patients_transfusion_request_fills_in_the_patient(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 1]], [[$this->centre, [[$this->prbc, 1]]]]);
        $unit = $this->receiveOne();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/inventory/{$unit->unit_id}/tag", ['transfusion_request_id' => $requirement->id])
            ->assertOk()
            ->assertJsonPath('unit.active_tag.patient.full_name', 'DELA CRUZ, Juan')
            ->assertJsonPath('unit.active_tag.patient.blood_type', 'O+')
            ->assertJsonPath('unit.active_tag.transfusion_request.reference_number', $requirement->reference_number);

        $tag = UnitTag::query()->sole();

        $this->assertSame($requirement->id, $tag->transfusion_request_id);
        $this->assertSame(54, $tag->patient_age);
        $this->assertSame('male', $tag->patient_sex);
        $this->assertSame($this->bloodType->id, $tag->patient_blood_type_id);
    }

    public function test_what_staff_enter_overrides_the_linked_request(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 1]], [[$this->centre, [[$this->prbc, 1]]]]);
        $unit = $this->receiveOne();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/inventory/{$unit->unit_id}/tag", [
                'transfusion_request_id' => $requirement->id,
                'patient_age' => 55,
                'patient_ward' => 'ICU',
            ])
            ->assertOk();

        $tag = UnitTag::query()->sole();

        $this->assertSame(55, $tag->patient_age);
        $this->assertSame('ICU', $tag->patient_ward);
        $this->assertSame('Dela Cruz', $tag->patient_surname);
    }

    public function test_another_hospitals_transfusion_request_cannot_be_linked(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $otherStaff = User::factory()->bloodBankStaff($otherHospital)->create();

        $theirs = $this->actingAs($otherStaff)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload(
                [[$this->prbc, 1]],
                [[$this->centre, [[$this->prbc, 1]]]]
            ))
            ->assertCreated()
            ->json('request.id');

        $unit = $this->receiveOne();

        $this->tagUnit($unit, ['transfusion_request_id' => $theirs])
            ->assertNotFound()
            ->assertJsonPath('code', 'transfusion_request_not_found');

        $this->assertSame(0, UnitTag::query()->count());
    }

    public function test_a_cancelled_transfusion_request_cannot_be_linked(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 1]], [[$this->centre, [[$this->prbc, 1]]]]);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$requirement->id}/cancel", ['reason' => 'Patient discharged'])
            ->assertOk();

        $unit = $this->receiveOne();

        $this->tagUnit($unit, ['transfusion_request_id' => $requirement->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'transfusion_request_closed');
    }

    public function test_crossmatching_moves_the_same_bag_out_of_storage_with_24_hours_to_transfuse(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00'));
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 06:15:00'));

        $this->act($unit, 'crossmatch')
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::TagCrossmatched->value)
            ->assertJsonPath('unit.active_tag.description', 'Awaiting transfusion')
            ->assertJsonPath('unit.active_tag.seconds_remaining', 86400);

        $tag = UnitTag::query()->sole();

        $this->assertSame(UnitTagStatus::TagCrossmatched, $tag->status);
        $this->assertSame($this->requester->id, $tag->crossmatched_by);
        $this->assertSame('2026-10-03 06:15:00', $tag->crossmatched_at->toDateTimeString());
        $this->assertSame('2026-10-04 06:15:00', $tag->transfusion_deadline_at->toDateTimeString());
        // The original deadline stays as history.
        $this->assertSame('2026-10-03 08:00:00', $tag->crossmatch_deadline_at->toDateTimeString());
    }

    public function test_transfusing_a_crossmatched_bag_uses_it_for_good(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();

        $this->act($unit, 'transfuse')
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Transfused->value)
            ->assertJsonPath('unit.active_tag', null);

        $tag = UnitTag::query()->sole();
        $this->assertSame(UnitTagStatus::Transfused, $tag->status);
        $this->assertSame($this->requester->id, $tag->transfused_by);

        // A transfused bag never returns to stock, by any door.
        $this->tagUnit($unit)->assertStatus(409)->assertJsonPath('code', 'unit_not_available');
        $this->act($unit, 'release', ['reason' => 'Mistake'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->act($unit, 'return')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->act($unit, 'discard', ['reason' => 'Mistake'])->assertStatus(409)->assertJsonPath('code', 'unit_not_discardable');

        $this->assertSame(HospitalUnitStatus::Transfused, $unit->fresh()->status);
    }

    public function test_a_bag_cannot_be_transfused_without_a_crossmatch(): void
    {
        $unit = $this->receiveOne();

        $this->act($unit, 'transfuse')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->act($unit, 'crossmatch')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->tagUnit($unit)->assertOk();

        $this->act($unit, 'transfuse')
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('message', 'This bag has not been crossmatched. Record the crossmatch before the transfusion.');

        $this->act($unit, 'return')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->assertSame(HospitalUnitStatus::TagAssigned, $unit->fresh()->status);
    }

    public function test_releasing_a_tag_assigned_bag_returns_it_to_stock_at_once(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->act($unit, 'release', ['reason' => 'Crossmatch incompatible'])
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Available->value)
            ->assertJsonPath('unit.active_tag', null);

        $tag = UnitTag::query()->sole();

        $this->assertSame(UnitTagStatus::UntaggedAssigned, $tag->status);
        $this->assertSame(UntagReason::ReleasedByStaff, $tag->untag_reason);
        $this->assertSame('Crossmatch incompatible', $tag->untag_note);
        $this->assertSame($this->requester->id, $tag->untagged_by);
        $this->assertNotNull($tag->untagged_at);
    }

    public function test_a_release_needs_a_reason(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->act($unit, 'release')->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->assertSame(HospitalUnitStatus::TagAssigned, $unit->fresh()->status);
    }

    public function test_releasing_a_bag_that_is_not_tagged_is_refused(): void
    {
        $unit = $this->receiveOne();

        $this->act($unit, 'release', ['reason' => 'Patient discharged'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition');
    }

    public function test_a_released_crossmatched_bag_waits_until_staff_confirm_its_return(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();

        $this->act($unit, 'release', ['reason' => 'Patient discharged'])
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::PendingReturn->value)
            ->assertJsonPath('unit.last_tag.status', UnitTagStatus::UntaggedCrossmatched->value);

        // Out of the fridge, so not stock yet.
        $this->tagUnit($unit, ['patient_surname' => 'Reyes'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_available');

        $this->act($unit, 'return')
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Available->value);

        $first = UnitTag::query()->sole();
        $this->assertSame(UnitTagStatus::UntaggedCrossmatched, $first->status);
        $this->assertSame($this->requester->id, $first->returned_by);

        // Re-tagging writes a second tag beside the first, which stays.
        $this->tagUnit($unit, ['patient_surname' => 'Reyes'])->assertOk();

        $this->assertSame(2, UnitTag::query()->count());
        $this->assertSame(UnitTagStatus::UntaggedCrossmatched, $first->fresh()->status);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/inventory/{$unit->unit_id}")
            ->assertOk()
            ->assertJsonCount(2, 'tags')
            ->assertJsonPath('tags.0.patient.surname', 'Reyes')
            ->assertJsonPath('tags.1.status', UnitTagStatus::UntaggedCrossmatched->value)
            ->assertJsonPath('tags.1.actors.returned_by', trim($this->requester->first_name.' '.$this->requester->last_name));
    }

    public function test_a_bag_pending_return_can_be_discarded_instead(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();
        $this->act($unit, 'release', ['reason' => 'Order cancelled'])->assertOk();

        $this->act($unit, 'discard', ['reason' => 'Out of cold chain for over 30 minutes'])
            ->assertOk()
            ->assertJsonPath('unit.status', HospitalUnitStatus::Discarded->value)
            ->assertJsonPath('unit.discard_reason', 'Out of cold chain for over 30 minutes');

        $fresh = $unit->fresh();
        $this->assertSame($this->requester->id, $fresh->discarded_by);
        $this->assertNotNull($fresh->discarded_at);

        $this->act($unit, 'return')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_a_tagged_bag_cannot_be_discarded_until_it_is_released(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->act($unit, 'discard', ['reason' => 'Damaged'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_discardable');

        $this->act($unit, 'crossmatch')->assertOk();

        $this->act($unit, 'discard', ['reason' => 'Damaged'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_discardable');
    }

    public function test_a_bag_past_its_date_cannot_be_tagged(): void
    {
        // 10:00 in Manila, so the UTC and operational dates agree.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00'));
        $unit = $this->receiveOne(expiresInDays: 2);

        $this->travel(4)->days();

        $this->tagUnit($unit)->assertStatus(409)->assertJsonPath('code', 'bag_expired');
        $this->assertSame(HospitalUnitStatus::Available, $unit->fresh()->status);
    }

    public function test_a_bag_that_expires_while_tagged_cannot_be_crossmatched(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00')); // 10:00 Manila
        $unit = $this->receiveOne(expiresInDays: 0);

        // A bag expiring today may still be tagged before the day ends.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 15:30:00')); // 23:30 Manila
        $this->tagUnit($unit)->assertOk();

        // Into the next operational day, still well inside the 24-hour window.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 17:30:00')); // 01:30 Manila, 3 Oct

        $this->act($unit, 'crossmatch')->assertStatus(409)->assertJsonPath('code', 'bag_expired');
        $this->assertSame(HospitalUnitStatus::TagAssigned, $unit->fresh()->status);
    }

    public function test_another_hospital_cannot_see_or_touch_the_bag(): void
    {
        $unit = $this->receiveOne();
        $otherStaff = User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->create();

        $this->actingAs($otherStaff)->getJson("/api/hospital/inventory/{$unit->unit_id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'unit_not_found');

        $this->actingAs($otherStaff)->postJson("/api/hospital/inventory/{$unit->unit_id}/tag", $this->tagPayload())
            ->assertNotFound();

        $this->actingAs($otherStaff)->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(HospitalUnitStatus::Available, $unit->fresh()->status);
    }

    public function test_blood_centre_staff_cannot_reach_hospital_stock(): void
    {
        $unit = $this->receiveOne();

        $this->actingAs($this->issuance)->getJson('/api/hospital/inventory')->assertForbidden();
        $this->actingAs($this->issuance)->postJson("/api/hospital/inventory/{$unit->unit_id}/tag", $this->tagPayload())
            ->assertForbidden();
    }

    public function test_an_unknown_bag_number_is_not_found(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/inventory/NO-SUCH-BAG/tag', $this->tagPayload())
            ->assertNotFound()
            ->assertJsonPath('code', 'unit_not_found');
    }

    public function test_each_transition_is_audited_against_the_bag_without_the_patients_name(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();
        $this->act($unit, 'release', ['reason' => 'Patient discharged'])->assertOk();
        $this->act($unit, 'return')->assertOk();

        $rows = AuditLog::query()
            ->where('action', 'like', 'hospital_inventory.%')
            ->where('action', '!=', 'hospital_inventory.stocked')
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['hospital_inventory.tagged', 'hospital_inventory.crossmatched', 'hospital_inventory.untagged', 'hospital_inventory.returned'],
            $rows->pluck('action')->all()
        );
        $this->assertSame(
            [['available', 'tag_assigned'], ['tag_assigned', 'tag_crossmatched'], ['tag_crossmatched', 'pending_return'], ['pending_return', 'available']],
            $rows->map(fn (AuditLog $row): array => [$row->context['previous_status'], $row->context['new_status']])->all()
        );

        foreach ($rows as $row) {
            $this->assertSame($unit->unit_id, $row->auditable_id);
            $this->assertSame($this->requester->id, $row->actor_id);
            $this->assertStringNotContainsString('Santos', json_encode($row->context));
        }

        $this->assertSame('released_by_staff', $rows[2]->context['untag_reason']);
        $this->assertSame('Patient discharged', $rows[2]->context['note']);
    }

    public function test_an_ended_tag_cannot_be_rewritten_or_deleted(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'release', ['reason' => 'Patient discharged'])->assertOk();

        $tag = UnitTag::query()->sole();

        try {
            $tag->forceFill(['untag_note' => 'Edited later'])->save();
            $this->fail('An ended tag was rewritten.');
        } catch (LogicException) {
        }

        try {
            UnitTag::query()->sole()->delete();
            $this->fail('A tag was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('Patient discharged', UnitTag::query()->sole()->untag_note);
    }

    public function test_a_tags_patient_cannot_be_changed_once_written(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->expectException(LogicException::class);

        UnitTag::query()->sole()->update(['patient_surname' => 'Someone Else']);
    }
}
