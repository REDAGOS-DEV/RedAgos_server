<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodUnitStatus;
use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Facility;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Suggesting how to split a patient's need across the network.
 *
 * Centres holding matching stock are listed earliest expiry first — the FEFO
 * rule, so blood closest to expiring is asked for first — and the suggestion
 * fills from the top until the quantity is covered. It is advice: nothing is
 * held until a centre approves its own allocation.
 */
class SourcingPlannerTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    private Facility $centreC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        $this->centreC = Facility::factory()->approved()->create(['name' => 'Digos Blood Center']);
    }

    public function test_the_centre_whose_stock_expires_first_is_suggested_first(): void
    {
        $this->stock($this->centre, $this->prbc, 1, expiresInDays: 2);
        $this->stock($this->otherCentre, $this->prbc, 3, expiresInDays: 4);
        $this->stock($this->centreC, $this->prbc, 4, expiresInDays: 9);

        $line = $this->plan([[$this->prbc, 5]])->json('lines.0');

        $this->assertSame(
            ['Davao Blood Center', 'Tagum Blood Center', 'Digos Blood Center'],
            array_column(array_column($line['facilities'], 'facility'), 'name')
        );
        $this->assertSame([1, 3, 1], array_column($line['facilities'], 'suggested'));
        $this->assertSame([1, 3, 4], array_column($line['facilities'], 'available'));
        $this->assertSame(5, $line['suggested_total']);
        $this->assertSame(0, $line['shortfall']);
    }

    public function test_a_deeper_shelf_breaks_a_tie_on_expiry(): void
    {
        $this->stock($this->centre, $this->prbc, 1, expiresInDays: 5);
        $this->stock($this->otherCentre, $this->prbc, 3, expiresInDays: 5);

        $line = $this->plan([[$this->prbc, 2]])->json('lines.0');

        $this->assertSame('Tagum Blood Center', $line['facilities'][0]['facility']['name']);
        $this->assertSame([2, 0], array_column($line['facilities'], 'suggested'));
    }

    public function test_what_the_network_cannot_cover_is_reported_as_a_shortfall(): void
    {
        $this->stock($this->centre, $this->prbc, 2);

        $this->plan([[$this->prbc, 5]])
            ->assertJsonPath('lines.0.suggested_total', 2)
            ->assertJsonPath('lines.0.shortfall', 3);
    }

    public function test_centres_holding_none_are_still_listed_to_ask(): void
    {
        $this->stock($this->centre, $this->prbc, 1);

        $others = $this->plan([[$this->prbc, 1]])->json('lines.0.other_facilities');

        $this->assertEqualsCanonicalizing(
            ['Tagum Blood Center', 'Digos Blood Center'],
            array_column($others, 'name')
        );
    }

    public function test_each_component_is_planned_on_its_own(): void
    {
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->otherCentre, $this->ffp, 1);

        $response = $this->plan([[$this->prbc, 2], [$this->ffp, 1]]);

        $response->assertJsonPath('lines.0.component.name', 'Packed RBC')
            ->assertJsonPath('lines.0.facilities.0.facility.name', 'Davao Blood Center')
            ->assertJsonPath('lines.1.component.name', 'Fresh Frozen Plasma')
            ->assertJsonPath('lines.1.facilities.0.facility.name', 'Tagum Blood Center');
    }

    public function test_units_that_cannot_be_issued_are_not_counted(): void
    {
        $this->stock($this->centre, $this->prbc, 2);
        BloodUnit::query()->limit(1)->update(['status' => BloodUnitStatus::Reserved]);

        $this->plan([[$this->prbc, 2]])
            ->assertJsonPath('lines.0.facilities.0.available', 1)
            ->assertJsonPath('lines.0.shortfall', 1);
    }

    public function test_a_plan_holds_nothing(): void
    {
        $this->stock($this->centre, $this->prbc, 3);

        $this->plan([[$this->prbc, 3]])->assertJsonPath('advisory', true);

        $this->assertDatabaseCount('request_allocations', 0);
        $this->assertSame(3, BloodUnit::query()->where('status', BloodUnitStatus::Available)->count());
    }

    public function test_a_blood_centre_account_cannot_plan(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/hospital/transfusion-requests/sourcing', [
                'blood_type_id' => $this->bloodType->id,
                'lines' => [['component_id' => $this->prbc->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    /**
     * @param  array<int, array{0: BloodComponent, 1: int}>  $lines
     */
    private function plan(array $lines): TestResponse
    {
        return $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests/sourcing', [
                'blood_type_id' => $this->bloodType->id,
                'lines' => array_map(fn (array $line): array => [
                    'component_id' => $line[0]->id,
                    'quantity' => $line[1],
                ], $lines),
            ])
            ->assertOk();
    }
}
