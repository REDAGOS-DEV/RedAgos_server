<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\RequestAllocation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Bringing requests settled under the receipt rule into line with dispatch.
 *
 * Before fulfilment was counted at dispatch, a request whose units had all left
 * the centre but not yet been received still said Processing. The command
 * re-derives those, touches nothing else, and a dry run changes nothing.
 */
class ResettleBloodRequestsTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_a_fully_released_request_left_processing_becomes_fulfilled(): void
    {
        $request = $this->legacyRequest(asked: 2, released: 2);

        $this->artisan('requests:resettle')->assertSuccessful();

        $request = $request->fresh();
        $this->assertSame(BloodRequestStatus::Fulfilled, $request->status);
        $this->assertNotNull($request->fulfilled_at);
        $this->assertNotNull($request->closed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.resettled']);
    }

    public function test_a_part_released_request_becomes_partially_fulfilled_and_stays_open(): void
    {
        $request = $this->legacyRequest(asked: 3, released: 1);

        $this->artisan('requests:resettle')->assertSuccessful();

        $request = $request->fresh();
        $this->assertSame(BloodRequestStatus::Partial, $request->status);
        $this->assertNull($request->closed_at);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $request = $this->legacyRequest(asked: 2, released: 2);

        $this->artisan('requests:resettle', ['--dry-run' => true])
            ->expectsOutputToContain('Would re-settle 1 request(s).')
            ->assertSuccessful();

        $this->assertSame(BloodRequestStatus::Processing, $request->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'request.resettled']);
    }

    public function test_decisions_are_never_rewritten(): void
    {
        $rejected = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->rejected()
            ->create();

        $this->artisan('requests:resettle')
            ->expectsOutputToContain('Every open request already matches its fulfilment.')
            ->assertSuccessful();

        $this->assertSame(BloodRequestStatus::Rejected, $rejected->fresh()->status);
    }

    /**
     * A request exactly as the receipt rule left it: units released, none
     * received, status still Processing.
     */
    private function legacyRequest(int $asked, int $released): BloodRequest
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->prbc, $asked)
            ->processing($this->issuance)
            ->create();

        $donation = Donation::factory()->create([
            'facility_id' => $this->centre->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        foreach (range(1, $released) as $ignored) {
            $unit = BloodUnit::factory()->create([
                'facility_id' => $this->centre->id,
                'blood_type_id' => $this->bloodType->id,
                'component_id' => $this->prbc->id,
                'donation_id' => $donation->id,
                'status' => BloodUnitStatus::Issued,
            ]);

            RequestAllocation::factory()->holding($request, $unit)->released($this->issuance)->create();
        }

        return $request->fresh();
    }
}
