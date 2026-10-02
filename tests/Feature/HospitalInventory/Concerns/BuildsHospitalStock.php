<?php

namespace Tests\Feature\HospitalInventory\Concerns;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\HospitalUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;

/**
 * A hospital blood bank with bags on its own shelf.
 *
 * Stock is built the only way the workflow allows: a centre allocates and
 * releases, and the hospital confirms receipt through the real endpoints.
 * A test therefore starts from hospital_units rows written exactly as receipt
 * writes them, never from a factory's idea of what receipt does.
 */
trait BuildsHospitalStock
{
    use BuildsFulfilmentScenarios;

    /**
     * Receive bags of one component from the scenario's centre, returning them as stocked.
     *
     * @return Collection<int, HospitalUnit>
     */
    protected function receiveStock(int $count = 1, ?BloodComponent $component = null, ?int $expiresInDays = null): Collection
    {
        $request = $this->releasedRequest($count, $component, $expiresInDays);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$request->id}/confirm-receipt")
            ->assertOk();

        return HospitalUnit::query()
            ->whereIn('unit_id', $request->allocations()->pluck('unit_id'))
            ->orderBy('unit_id')
            ->get();
    }

    /**
     * Receive a single bag and return it.
     */
    protected function receiveOne(?int $expiresInDays = null): HospitalUnit
    {
        return $this->receiveStock(1, null, $expiresInDays)->first();
    }

    /**
     * A request to the scenario's centre whose bags have been dispatched but not yet received.
     */
    protected function releasedRequest(int $count = 1, ?BloodComponent $component = null, ?int $expiresInDays = null): BloodRequest
    {
        $component ??= $this->prbc;

        $this->stock($this->centre, $component, $count, $expiresInDays);

        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->state(['blood_type_id' => $this->bloodType->id])
            ->withComponents([[$component, $count]])
            ->create();

        $this->allocateAndRelease($request);

        return $request;
    }

    /**
     * A complete patient for the tag form.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function tagPayload(array $overrides = []): array
    {
        return [
            'patient_surname' => 'Santos',
            'patient_first_name' => 'Maria',
            'patient_middle_name' => 'Lopez',
            'patient_age' => 41,
            'patient_sex' => 'female',
            'patient_record_number' => 'HRN-000123',
            'patient_ward' => 'Surgical Ward 3',
            'attending_physician' => 'Dr. Jose Rizal',
            ...$overrides,
        ];
    }

    /**
     * Tag a bag to the scenario's patient as the hospital's staff.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function tagUnit(HospitalUnit $unit, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->requester)
            ->postJson("/api/hospital/inventory/{$unit->unit_id}/tag", $this->tagPayload($overrides));
    }

    /**
     * Take one of the bag actions — crossmatch, transfuse, release, return, discard — as the hospital's staff.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function act(HospitalUnit $unit, string $action, array $payload = []): TestResponse
    {
        return $this->actingAs($this->requester)
            ->postJson("/api/hospital/inventory/{$unit->unit_id}/{$action}", $payload);
    }
}
