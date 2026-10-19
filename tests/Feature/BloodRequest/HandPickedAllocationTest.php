<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Reserving the bags staff picked from the shelf (project owner, 2026-10-19).
 *
 * The scenario request asks for PRBC 2, FFP 2 and platelets 1, all O+. Staff
 * see the units each line may take, first-expiring first, and choose; the
 * server holds exactly those, or refuses the whole pick and says which unit
 * and why.
 */
class HandPickedAllocationTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_each_line_lists_the_units_it_may_take_first_expiring_first(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 1, 20);
        $this->stock($this->centre, $this->prbc, 1, 5);
        // None of these may be picked for the O+ PRBC line.
        $this->stock($this->centre, $this->prbc, 1, 10, BloodType::firstOrCreate(['code' => 'A+'], ['label' => 'A+']));
        $this->stock($this->centre, $this->prbc, 1, -1);
        $this->stock($this->otherCentre, $this->prbc, 1, 3);
        $held = $this->unitOf($this->prbc, 15);
        RequestAllocation::factory()->create(['unit_id' => $held->id]);
        $held->update(['status' => BloodUnitStatus::Reserved]);

        $response = $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/units")
            ->assertOk()
            ->assertJsonPath('reference_number', $request->reference_number);

        $prbc = collect($response->json('lines'))->firstWhere('component.id', $this->prbc->id);

        $this->assertSame(2, $prbc['allocatable']);
        $this->assertSame('O+', $prbc['blood_type']['code']);
        $this->assertCount(2, $prbc['units']);
        $this->assertSame([5, 20], array_column($prbc['units'], 'days_remaining'));
    }

    public function test_the_units_picked_are_the_ones_held_not_the_oldest(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 1, 3);
        $later = $this->unitOf($this->prbc, 25);

        $response = $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['unit_ids' => [$later->id]])
            ->assertOk()
            ->assertJsonPath('held_total', 1)
            ->assertJsonPath('status', BloodRequestStatus::Processing->value);

        $this->assertSame([$later->id], $response->json('allocated_units'));
        $this->assertSame(BloodUnitStatus::Reserved, $later->fresh()->status);
        $this->assertSame(1, BloodUnit::query()->where('status', BloodUnitStatus::Available)->count());
    }

    public function test_a_pick_across_lines_is_filed_against_each_units_own_line(): void
    {
        $request = $this->scenarioRequest();
        $prbc = $this->unitOf($this->prbc, 10);
        $ffp = $this->unitOf($this->ffp, 10);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['unit_ids' => [$prbc->id, $ffp->id]])
            ->assertOk()
            ->assertJsonPath('held_total', 2);

        $this->assertSame($this->lineFor($request, $this->prbc), RequestAllocation::query()->where('unit_id', $prbc->id)->value('request_item_id'));
        $this->assertSame($this->lineFor($request, $this->ffp), RequestAllocation::query()->where('unit_id', $ffp->id)->value('request_item_id'));
    }

    public function test_a_unit_the_request_cannot_take_refuses_the_whole_pick_by_name(): void
    {
        $request = $this->scenarioRequest();
        $good = $this->unitOf($this->prbc, 10);
        $wrongType = $this->unitOf($this->prbc, 10, BloodType::firstOrCreate(['code' => 'B-'], ['label' => 'B-']));
        $expired = $this->unitOf($this->prbc, -2);
        $elsewhere = $this->unitOf($this->prbc, 10, null, $this->otherCentre);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", [
                'unit_ids' => [$good->id, $wrongType->id, $expired->id, $elsewhere->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'unit_ids.1' => 'is not a blood type and component this request asks for',
                'unit_ids.2' => 'is no longer available',
                'unit_ids.3' => "is not in this centre's stock",
            ]);

        // Nothing at all was held, the good unit included.
        $this->assertSame(BloodUnitStatus::Available, $good->fresh()->status);
        $this->assertSame(0, RequestAllocation::query()->count());
    }

    public function test_more_of_a_component_than_still_needed_is_refused(): void
    {
        $request = $this->scenarioRequest();
        $units = collect(range(1, 3))->map(fn (int $i): BloodUnit => $this->unitOf($this->prbc, 10 + $i));

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['unit_ids' => $units->pluck('id')->all()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_ids.2' => 'was picked than this request still needs']);
    }

    public function test_a_unit_already_held_for_another_request_is_refused(): void
    {
        $request = $this->scenarioRequest();
        $other = $this->scenarioRequest();
        $unit = $this->unitOf($this->prbc, 10);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$other->id}/allocate", ['unit_ids' => [$unit->id]])
            ->assertOk();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['unit_ids' => [$unit->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_ids.0' => 'is no longer available']);
    }

    public function test_picking_needs_the_ability_to_reserve(): void
    {
        $request = $this->scenarioRequest();
        $billing = User::factory()->bloodCenterStaff($this->centre, Department::Billing)->create();

        $this->actingAs($billing)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/units")
            ->assertForbidden();
    }

    /**
     * One issuable unit, returned so a test can name it.
     */
    private function unitOf($component, int $expiresInDays, ?BloodType $bloodType = null, ?Facility $facility = null): BloodUnit
    {
        // Unit ids are random and created within the same second, so the new
        // one is found by what was not there before.
        $before = BloodUnit::query()->pluck('id')->all();

        $this->stock($facility ?? $this->centre, $component, 1, $expiresInDays, $bloodType);

        return BloodUnit::query()->whereNotIn('id', $before)->sole();
    }
}
