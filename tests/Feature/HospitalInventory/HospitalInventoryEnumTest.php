<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\HospitalUnit;
use App\Models\UnitTag;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * The transition maps are the specification's §14, written as code.
 *
 * Every valid transition it lists must be allowed, and every invalid example
 * it gives must be refused — by the enums, which the service and the model
 * guard both read.
 */
class HospitalInventoryEnumTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    public function test_only_the_two_active_tag_statuses_claim_a_bag(): void
    {
        $this->assertSame(['tag_assigned', 'tag_crossmatched'], UnitTagStatus::claimingValues());
    }

    public function test_the_valid_transitions_of_the_specification_are_allowed(): void
    {
        $this->assertTrue(HospitalUnitStatus::Available->canTransitionTo(HospitalUnitStatus::TagAssigned));
        $this->assertTrue(HospitalUnitStatus::TagAssigned->canTransitionTo(HospitalUnitStatus::TagCrossmatched));
        $this->assertTrue(HospitalUnitStatus::TagAssigned->canTransitionTo(HospitalUnitStatus::Available));
        $this->assertTrue(HospitalUnitStatus::TagCrossmatched->canTransitionTo(HospitalUnitStatus::Transfused));
        $this->assertTrue(HospitalUnitStatus::TagCrossmatched->canTransitionTo(HospitalUnitStatus::PendingReturn));
        $this->assertTrue(HospitalUnitStatus::PendingReturn->canTransitionTo(HospitalUnitStatus::Available));

        $this->assertTrue(UnitTagStatus::TagAssigned->canTransitionTo(UnitTagStatus::TagCrossmatched));
        $this->assertTrue(UnitTagStatus::TagAssigned->canTransitionTo(UnitTagStatus::UntaggedAssigned));
        $this->assertTrue(UnitTagStatus::TagCrossmatched->canTransitionTo(UnitTagStatus::Transfused));
        $this->assertTrue(UnitTagStatus::TagCrossmatched->canTransitionTo(UnitTagStatus::UntaggedCrossmatched));
    }

    public function test_the_invalid_examples_of_the_specification_are_refused(): void
    {
        // Available → Transfused, and Tag Assigned → Transfused: no crossmatch.
        $this->assertFalse(HospitalUnitStatus::Available->canTransitionTo(HospitalUnitStatus::Transfused));
        $this->assertFalse(HospitalUnitStatus::TagAssigned->canTransitionTo(HospitalUnitStatus::Transfused));
        $this->assertFalse(UnitTagStatus::TagAssigned->canTransitionTo(UnitTagStatus::Transfused));

        // Untagged Assigned → Transfused without a new lifecycle.
        $this->assertFalse(UnitTagStatus::UntaggedAssigned->canTransitionTo(UnitTagStatus::Transfused));

        // Transfused → Available: never reused.
        $this->assertFalse(HospitalUnitStatus::Transfused->canTransitionTo(HospitalUnitStatus::Available));
        $this->assertSame([], HospitalUnitStatus::Transfused->transitions());
        $this->assertSame([], UnitTagStatus::Transfused->transitions());
    }

    public function test_each_active_tag_ends_in_its_own_untagged_state_and_reason(): void
    {
        $this->assertSame(UnitTagStatus::UntaggedAssigned, UnitTagStatus::TagAssigned->untaggedState());
        $this->assertSame(UnitTagStatus::UntaggedCrossmatched, UnitTagStatus::TagCrossmatched->untaggedState());
        $this->assertNull(UnitTagStatus::Transfused->untaggedState());

        $this->assertSame(UntagReason::CrossmatchDeadlineExpired, UntagReason::deadlineFor(UnitTagStatus::TagAssigned));
        $this->assertSame(UntagReason::TransfusionDeadlineExpired, UntagReason::deadlineFor(UnitTagStatus::TagCrossmatched));
    }

    public function test_a_tagged_or_used_bag_cannot_be_discarded(): void
    {
        $this->assertTrue(HospitalUnitStatus::Available->isDiscardable());
        $this->assertTrue(HospitalUnitStatus::PendingReturn->isDiscardable());
        $this->assertTrue(HospitalUnitStatus::Expired->isDiscardable());

        $this->assertFalse(HospitalUnitStatus::TagAssigned->isDiscardable());
        $this->assertFalse(HospitalUnitStatus::TagCrossmatched->isDiscardable());
        $this->assertFalse(HospitalUnitStatus::Transfused->isDiscardable());
        $this->assertFalse(HospitalUnitStatus::Discarded->isDiscardable());
    }

    public function test_the_columns_accept_every_enum_value(): void
    {
        $this->buildScenario();
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        foreach (HospitalUnitStatus::values() as $status) {
            DB::table('hospital_units')->where('id', $unit->id)->update(['status' => $status]);
            $this->assertSame($status, HospitalUnit::query()->findOrFail($unit->id)->status->value);
        }

        $tagId = UnitTag::query()->value('id');

        foreach (UnitTagStatus::values() as $status) {
            DB::table('unit_tags')->where('id', $tagId)->update(['status' => $status]);
            $this->assertSame($status, UnitTag::query()->findOrFail($tagId)->status->value);
        }

        foreach (UntagReason::values() as $reason) {
            DB::table('unit_tags')->where('id', $tagId)->update(['untag_reason' => $reason]);
            $this->assertSame($reason, UnitTag::query()->findOrFail($tagId)->untag_reason->value);
        }
    }
}
