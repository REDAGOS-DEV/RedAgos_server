<?php

namespace Tests\Concerns;

use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Testing\TestResponse;

/**
 * Confirming a replenishment order, and dispatching it: the units are picked as it goes.
 */
trait PlansSupply
{
    /**
     * Confirm a replenishment order as centre staff and expect it to succeed.
     *
     * Every open line is committed in full unless $commitments (line id =>
     * quantity) says otherwise. Delivered tomorrow at 09:00 Manila.
     *
     * @param  array<int, int>|null  $commitments
     * @param  array<string, mixed>  $overrides
     */
    protected function planSupply(BloodRequest $request, User $staff, ?array $commitments = null, array $overrides = []): BloodRequest
    {
        $this->sendSupplyPlan($request, $staff, $commitments, $overrides)->assertOk();

        return $request->fresh();
    }

    /**
     * Dispatch a confirmed order as $staff and return the response.
     *
     * The units named, or, given none, the first-expiring ones each line may
     * still take.
     *
     * @param  array<int, string>|null  $unitIds
     * @param  array<string, mixed>  $extra
     */
    protected function sendDispatch(BloodRequest $request, User $staff, ?array $unitIds = null, array $extra = []): TestResponse
    {
        return $this->actingAs($staff)->postJson("/api/blood-center/blood-requests/{$request->id}/dispatch", [
            'unit_ids' => $unitIds ?? $this->unitsInReach($request, $staff),
            ...$extra,
        ]);
    }

    /**
     * The first-expiring units each line of an order may still take.
     *
     * @return array<int, string>
     */
    protected function unitsInReach(BloodRequest $request, User $staff): array
    {
        return collect($this->actingAs($staff)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/dispatch-units")
            ->assertOk()
            ->json('lines'))
            ->flatMap(fn (array $line): array => array_column(array_slice($line['units'], 0, $line['allocatable']), 'id'))
            ->values()
            ->all();
    }

    /**
     * Send a supply plan for a request, as $staff, and return the response.
     *
     * @param  array<int, int>|null  $commitments
     * @param  array<string, mixed>  $overrides
     */
    protected function sendSupplyPlan(BloodRequest $request, User $staff, ?array $commitments = null, array $overrides = []): TestResponse
    {
        return $this->actingAs($staff)
            ->putJson("/api/blood-center/blood-requests/{$request->id}/supply-plan", $this->supplyPlanPayload($request, $commitments, $overrides));
    }

    /**
     * The payload sendSupplyPlan() sends.
     *
     * @param  array<int, int>|null  $commitments
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function supplyPlanPayload(BloodRequest $request, ?array $commitments = null, array $overrides = []): array
    {
        $lines = $request->items()->whereNull('closed_at')->orderBy('id')->get()
            ->map(fn (BloodRequestItem $item): array => [
                'request_item_id' => $item->id,
                'committed_quantity' => $commitments[$item->id] ?? $item->quantity,
            ])
            ->all();

        return [
            'handover_method' => 'delivery',
            'scheduled_at' => OperationalDay::today()->addDay()->setTime(9, 0)->toIso8601String(),
            'handover_address' => 'Blood bank, ground floor, Southern Philippines Medical Center',
            'contact_name' => 'Ana Reyes',
            'contact_phone' => '(082) 227-2731',
            'notes' => 'Bring a validated transport box.',
            'lines' => $lines,
            ...$overrides,
        ];
    }
}
