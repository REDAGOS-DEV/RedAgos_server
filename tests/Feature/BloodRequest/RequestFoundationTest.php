<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BillingStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Service\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The schema guarantees the request workflow is built on.
 *
 * These are foundation tests, not workflow tests: no service exists yet. What
 * they pin down is the shape of the data — that the two facilities stay
 * distinguishable, that a bag cannot be promised to two requests at once, and
 * that giving up a hold genuinely frees the bag rather than burning it, which
 * the original global unique constraint on unit_id did.
 */
class RequestFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_request_records_the_facility_it_came_from_and_the_one_it_went_to(): void
    {
        $hospital = Facility::factory()->create();
        $bloodCenter = Facility::factory()->create();

        $request = BloodRequest::factory()
            ->raisedBy($hospital)
            ->addressedTo($bloodCenter)
            ->create();

        $this->assertSame($hospital->id, $request->requestingFacility->id);
        $this->assertSame($bloodCenter->id, $request->targetFacility->id);
        $this->assertNotSame($request->requestingFacility->id, $request->targetFacility->id);
    }

    public function test_the_two_facility_sides_are_queried_separately(): void
    {
        $hospital = Facility::factory()->create();
        $bloodCenter = Facility::factory()->create();

        BloodRequest::factory()->raisedBy($hospital)->addressedTo($bloodCenter)->create();

        $this->assertCount(1, BloodRequest::query()->raisedBy($hospital->id)->get());
        $this->assertCount(0, BloodRequest::query()->raisedBy($bloodCenter->id)->get());

        $this->assertCount(1, BloodRequest::query()->addressedTo($bloodCenter->id)->get());
        $this->assertCount(0, BloodRequest::query()->addressedTo($hospital->id)->get());
    }

    public function test_a_facility_sees_its_incoming_and_outgoing_requests_apart(): void
    {
        $hospital = Facility::factory()->create();
        $bloodCenter = Facility::factory()->create();

        BloodRequest::factory()->raisedBy($hospital)->addressedTo($bloodCenter)->create();

        $this->assertCount(1, $hospital->outgoingBloodRequests);
        $this->assertCount(0, $hospital->incomingBloodRequests);

        $this->assertCount(1, $bloodCenter->incomingBloodRequests);
        $this->assertCount(0, $bloodCenter->outgoingBloodRequests);
    }

    public function test_status_and_urgency_come_back_as_enums(): void
    {
        $request = BloodRequest::factory()->emergency()->create()->fresh();

        $this->assertSame(BloodRequestStatus::Pending, $request->status);
        $this->assertSame(UrgencyLevel::Emergency, $request->urgency_level);
        $this->assertTrue($request->urgency_level->isPrioritised());
    }

    public function test_the_queue_puts_emergencies_first_then_the_oldest(): void
    {
        $bloodCenter = Facility::factory()->create();

        $newerRoutine = BloodRequest::factory()->addressedTo($bloodCenter)
            ->create(['request_date' => now()->subDays(3)]);
        $newestEmergency = BloodRequest::factory()->addressedTo($bloodCenter)->emergency()
            ->create(['request_date' => now()]);
        $oldestRoutine = BloodRequest::factory()->addressedTo($bloodCenter)
            ->create(['request_date' => now()->subDays(5)]);

        $queue = BloodRequest::query()->addressedTo($bloodCenter->id)->triaged()->pluck('id')->all();

        $this->assertSame(
            [$newestEmergency->id, $oldestRoutine->id, $newerRoutine->id],
            $queue,
            'An emergency outranks an older routine request, and routine requests age in.'
        );
    }

    public function test_a_unit_cannot_be_held_by_two_requests_at_once(): void
    {
        $unit = BloodUnit::factory()->create();
        $first = BloodRequest::factory()->create();
        $second = BloodRequest::factory()->create();

        RequestAllocation::factory()->holding($first, $unit)->create();

        $this->expectException(QueryException::class);

        RequestAllocation::factory()->holding($second, $unit)->create();
    }

    public function test_a_released_unit_still_cannot_be_claimed_by_another_request(): void
    {
        $unit = BloodUnit::factory()->create();
        $first = BloodRequest::factory()->create();
        $second = BloodRequest::factory()->create();

        RequestAllocation::factory()->holding($first, $unit)->released()->create();

        $this->expectException(QueryException::class);

        RequestAllocation::factory()->holding($second, $unit)->create();
    }

    public function test_giving_up_a_hold_frees_the_unit_for_another_request(): void
    {
        $unit = BloodUnit::factory()->create();
        $first = BloodRequest::factory()->create();
        $second = BloodRequest::factory()->create();

        RequestAllocation::factory()->holding($first, $unit)->cancelled()->create();

        $reallocated = RequestAllocation::factory()->holding($second, $unit)->create();

        $this->assertSame($second->id, $reallocated->request_id);
        $this->assertCount(2, RequestAllocation::query()->where('unit_id', $unit->id)->get());
        $this->assertCount(1, RequestAllocation::query()->where('unit_id', $unit->id)->claiming()->get());
    }

    public function test_only_the_claiming_hold_is_the_units_active_one(): void
    {
        $unit = BloodUnit::factory()->create();

        RequestAllocation::factory()->holding(BloodRequest::factory()->create(), $unit)->cancelled()->create();
        $live = RequestAllocation::factory()->holding(BloodRequest::factory()->create(), $unit)->create();

        $this->assertSame($live->id, $unit->fresh()->activeAllocation->id);
    }

    public function test_receipt_is_recorded_separately_from_dispatch(): void
    {
        $allocation = RequestAllocation::factory()->released()->create()->fresh();

        $this->assertSame(AllocationStatus::Released, $allocation->status);
        $this->assertNotNull($allocation->released_at);
        $this->assertNull(
            $allocation->received_at,
            'Dispatching a unit must not assert that it arrived.'
        );
    }

    public function test_a_request_is_billed_at_most_once(): void
    {
        $request = BloodRequest::factory()->create();

        Billing::factory()->create(['request_id' => $request->id]);

        $this->expectException(QueryException::class);

        Billing::factory()->create(['request_id' => $request->id]);
    }

    public function test_a_subsidised_statement_is_zero_rated_and_clears_release(): void
    {
        $billing = Billing::factory()->create()->fresh();

        $this->assertTrue($billing->isZeroRated());
        $this->assertSame(BillingStatus::Paid, $billing->status);
        $this->assertTrue($billing->clearsRelease());
    }

    public function test_an_unsettled_or_part_settled_statement_does_not_clear_release(): void
    {
        $this->assertFalse(Billing::factory()->chargeable()->create()->clearsRelease());
        $this->assertFalse(Billing::factory()->partiallyPaid()->create()->clearsRelease());

        $this->assertTrue(Billing::factory()->paid()->create()->clearsRelease());
        $this->assertTrue(Billing::factory()->void()->create()->clearsRelease());
    }

    public function test_the_audit_trail_accepts_a_blood_units_string_key(): void
    {
        $unit = BloodUnit::factory()->create(['id' => 'RA4-118-01']);
        $actor = User::factory()->create();

        app(AuditLogger::class)->record($actor, 'allocation.reserved', $unit, ['request_id' => 1]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'allocation.reserved',
            'auditable_type' => BloodUnit::class,
            'auditable_id' => 'RA4-118-01',
        ]);

        $this->assertSame('RA4-118-01', AuditLog::query()->latest('id')->first()->auditable_id);
    }
}
