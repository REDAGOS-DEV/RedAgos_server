<?php

namespace Tests\Feature\Receiving\Concerns;

use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\ReplenishmentSchedule;
use App\Models\WeeklyRequest;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * Request days and weekly requests, for a test that already uses BuildsFulfilmentScenarios.
 *
 * Time is fixed in Manila: Monday 5 October 2026, 09:00. A schedule of
 * Monday, Wednesday and Friday therefore makes today a request day.
 */
trait SendsWeeklyRequests
{
    /**
     * Monday, 5 October 2026, at 09:00 Manila.
     */
    protected function travelToMonday(string $time = '09:00'): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", 'Asia/Manila'));
    }

    /**
     * Keep request days with a centre, Monday, Wednesday and Friday unless told otherwise.
     *
     * @param  array<int, int>  $days
     */
    protected function scheduleWith(Facility $centre, array $days = [1, 3, 5]): ReplenishmentSchedule
    {
        return ReplenishmentSchedule::factory()->between($this->hospital, $centre)->on($days)->create();
    }

    /**
     * Send a weekly request through the portal.
     *
     * Cells are [blood type, component, quantity].
     *
     * @param  array<int, array{0: BloodType, 1: BloodComponent, 2: int}>  $cells
     * @param  array<string, mixed>  $overrides
     */
    protected function sendWeekly(array $cells, ?Facility $centre = null, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->requester)
            ->postJson('/api/hospital/weekly-requests', $this->weeklyPayload($cells, $centre, $overrides));
    }

    /**
     * Send a weekly request that must succeed, and return it.
     *
     * @param  array<int, array{0: BloodType, 1: BloodComponent, 2: int}>  $cells
     */
    protected function sentWeekly(array $cells, ?Facility $centre = null): WeeklyRequest
    {
        $id = $this->sendWeekly($cells, $centre)->assertCreated()->json('weekly_request.id');

        return WeeklyRequest::query()->findOrFail($id);
    }

    /**
     * The payload sendWeekly() sends.
     *
     * @param  array<int, array{0: BloodType, 1: BloodComponent, 2: int}>  $cells
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function weeklyPayload(array $cells, ?Facility $centre = null, array $overrides = []): array
    {
        return [
            'target_facility_id' => ($centre ?? $this->centre)->id,
            'lines' => array_map(fn (array $cell): array => [
                'blood_type_id' => $cell[0]->id,
                'component_id' => $cell[1]->id,
                'quantity' => $cell[2],
            ], $cells),
            ...$overrides,
        ];
    }
}
