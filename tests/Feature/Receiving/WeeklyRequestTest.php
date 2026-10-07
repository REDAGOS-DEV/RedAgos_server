<?php

namespace Tests\Feature\Receiving;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestPurpose;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\User;
use App\Models\WeeklyRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\Feature\Receiving\Concerns\SendsWeeklyRequests;
use Tests\TestCase;

/**
 * Sending a weekly request, and the request days that govern it.
 *
 * The rules worth naming: a weekly request goes only on one of the hospital's
 * request days for that centre, at most once a day; it becomes one ordinary
 * replenishment request per blood type, each routine; and the status says,
 * per centre, whether today's request is due, sent, or which recent request
 * days went without one.
 */
class WeeklyRequestTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase, SendsWeeklyRequests;

    private BloodType $aPositive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->aPositive = BloodType::firstOrCreate(['code' => 'A+'], ['label' => 'A+']);
        $this->travelToMonday();
    }

    public function test_a_weekly_request_sends_one_routine_replenishment_per_blood_type(): void
    {
        $this->scheduleWith($this->centre);

        $response = $this->sendWeekly([
            [$this->bloodType, $this->prbc, 4],
            [$this->bloodType, $this->ffp, 2],
            [$this->aPositive, $this->prbc, 3],
        ]);

        $response->assertCreated()
            ->assertJsonPath('weekly_request.reference_number', "WR-{$this->hospital->id}-0001")
            ->assertJsonPath('weekly_request.request_day', '2026-10-05')
            ->assertJsonPath('weekly_request.target_facility.id', $this->centre->id)
            ->assertJsonPath('weekly_request.status', 'submitted')
            ->assertJsonPath('weekly_request.totals.requested', 9)
            ->assertJsonCount(2, 'weekly_request.requests');

        $weekly = WeeklyRequest::query()->sole();
        $requests = BloodRequest::query()->where('weekly_request_id', $weekly->id)->orderBy('blood_type_id')->get();

        $this->assertCount(2, $requests);

        foreach ($requests as $request) {
            $this->assertSame(RequestPurpose::Replenishment, $request->request_purpose);
            $this->assertSame(UrgencyLevel::Routine, $request->urgency_level);
            $this->assertSame(BloodRequestStatus::Pending, $request->status);
            $this->assertSame($this->centre->id, $request->target_facility_id);
            $this->assertSame($this->requester->id, $request->requested_by);
        }

        $oPositive = $requests->firstWhere('blood_type_id', $this->bloodType->id);
        $this->assertSame([$this->prbc->id => 4, $this->ffp->id => 2], $oPositive->items()->orderBy('id')->pluck('quantity', 'component_id')->all());
        $this->assertSame(
            [$this->prbc->id => 3],
            $requests->firstWhere('blood_type_id', $this->aPositive->id)->items()->pluck('quantity', 'component_id')->all()
        );

        // Each blood type takes its own number in the hospital's one RQ sequence.
        $this->assertEqualsCanonicalizing(
            ["RQ-{$this->hospital->id}-0001", "RQ-{$this->hospital->id}-0002"],
            $requests->pluck('reference_number')->all()
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weekly_request.submitted',
            'actor_id' => $this->requester->id,
            'auditable_type' => WeeklyRequest::class,
        ]);
    }

    public function test_the_blood_requests_name_their_weekly_request(): void
    {
        $this->scheduleWith($this->centre);
        $weekly = $this->sentWeekly([[$this->bloodType, $this->prbc, 2]]);
        $request = BloodRequest::query()->where('weekly_request_id', $weekly->id)->sole();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.weekly_request.id', $weekly->id)
            ->assertJsonPath('request.weekly_request.reference_number', $weekly->reference_number);

        $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.weekly_request.reference_number', $weekly->reference_number);
    }

    public function test_it_is_refused_on_a_day_that_is_not_a_request_day(): void
    {
        $this->scheduleWith($this->centre);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_a_request_day');

        $this->assertDatabaseCount('weekly_requests', 0);
        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_the_request_day_is_the_manila_date_not_the_utc_one(): void
    {
        // Wednesday 07:00 in Manila is still Tuesday in UTC.
        $this->scheduleWith($this->centre, [3]);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 07:00', 'Asia/Manila'));

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])
            ->assertCreated()
            ->assertJsonPath('weekly_request.request_day', '2026-10-07');
    }

    public function test_it_is_refused_without_request_days_for_that_centre(): void
    {
        $this->scheduleWith($this->otherCentre);

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_request_schedule');

        $this->assertDatabaseCount('weekly_requests', 0);
    }

    public function test_only_one_weekly_request_goes_to_a_centre_each_request_day(): void
    {
        $this->scheduleWith($this->centre);
        $this->scheduleWith($this->otherCentre);

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])->assertCreated();

        $this->sendWeekly([[$this->bloodType, $this->prbc, 1]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'weekly_request_exists');

        $this->sendWeekly([[$this->bloodType, $this->prbc, 1]], $this->otherCentre)
            ->assertCreated()
            ->assertJsonPath('weekly_request.reference_number', "WR-{$this->hospital->id}-0002");

        $this->assertDatabaseCount('weekly_requests', 2);
        $this->assertDatabaseCount('blood_requests', 2);
    }

    public function test_a_weekly_request_carries_no_indication(): void
    {
        $this->scheduleWith($this->centre);

        // An indication is a patient's clinical certification; this restocks the shelves.
        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]], null, [
            'lines' => [[
                'blood_type_id' => $this->bloodType->id,
                'component_id' => $this->prbc->id,
                'quantity' => 2,
                'indication_code' => 'R-1',
            ]],
        ])->assertCreated();

        $this->assertNull(BloodRequest::query()->sole()->items()->sole()->indication_code);
    }

    public function test_a_line_must_ask_for_at_least_one_unit(): void
    {
        $this->scheduleWith($this->centre);

        $this->sendWeekly([[$this->bloodType, $this->prbc, 0]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.quantity']);

        $this->sendWeekly([[$this->bloodType, $this->prbc, 101]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.quantity']);

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/weekly-requests', ['target_facility_id' => $this->centre->id, 'lines' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines']);
    }

    public function test_the_same_component_and_blood_type_twice_is_refused(): void
    {
        $this->scheduleWith($this->centre);

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2], [$this->bloodType, $this->prbc, 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.1.component_id']);

        // The same component in another blood type is a different line.
        $this->sendWeekly([[$this->bloodType, $this->prbc, 2], [$this->aPositive, $this->prbc, 1]])->assertCreated();
    }

    public function test_a_weekly_request_cannot_go_to_another_blood_bank(): void
    {
        $otherBank = Facility::factory()->bloodBank()->approved()->create();

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]], $otherBank)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_facility_id']);
    }

    public function test_the_centre_is_told_of_each_blood_request(): void
    {
        $this->scheduleWith($this->centre);

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2], [$this->aPositive, $this->prbc, 1]])->assertCreated();

        $this->assertSame(2, $this->issuance->notifications()->count());
    }

    public function test_a_blood_centre_account_cannot_send_one(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/hospital/weekly-requests', $this->weeklyPayload([[$this->bloodType, $this->prbc, 2]]))
            ->assertForbidden();
    }

    public function test_weekly_requests_are_listed_and_shown_only_to_the_hospital_that_sent_them(): void
    {
        $this->scheduleWith($this->centre);
        $weekly = $this->sentWeekly([[$this->bloodType, $this->prbc, 2]]);

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $stranger = User::factory()->bloodBankStaff($otherHospital)->create();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/weekly-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_number', $weekly->reference_number)
            ->assertJsonPath('data.0.totals.requested', 2);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/weekly-requests/{$weekly->id}")
            ->assertOk()
            ->assertJsonPath('weekly_request.requests.0.allocations', []);

        $this->actingAs($stranger)->getJson('/api/hospital/weekly-requests')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($stranger)
            ->getJson("/api/hospital/weekly-requests/{$weekly->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'weekly_request_not_found');
    }

    public function test_the_status_says_whether_today_is_due_and_which_request_days_were_missed(): void
    {
        $schedule = $this->scheduleWith($this->centre);
        $schedule->forceFill(['created_at' => CarbonImmutable::parse('2026-09-21 08:00', 'Asia/Manila')])->save();

        // Monday 21 and Wednesday 23 September were sent; every request day since was not.
        foreach (['2026-09-21', '2026-09-23'] as $day) {
            WeeklyRequest::factory()->raisedBy($this->hospital, $this->requester)->addressedTo($this->centre)
                ->create(['request_day' => $day]);
        }

        $status = $this->actingAs($this->requester)
            ->getJson('/api/hospital/weekly-requests/status')
            ->assertOk()
            ->assertJsonPath('as_of', '2026-10-05')
            ->assertJsonPath('today_weekday', 1)
            ->assertJsonPath('schedules.0.schedule.days_label', 'Mon, Wed, Fri')
            ->assertJsonPath('schedules.0.is_request_day', true)
            ->assertJsonPath('schedules.0.due_today', true)
            ->assertJsonPath('schedules.0.sent_today', null)
            ->assertJsonPath('schedules.0.next_request_day', '2026-10-07')
            ->assertJsonPath('schedules.0.missed_count', 4)
            ->assertJsonPath('schedules.0.last_missed_day', '2026-10-02')
            ->json('schedules.0.recent');

        $this->assertSame(
            ['2026-10-02', '2026-09-30', '2026-09-28', '2026-09-25', '2026-09-23', '2026-09-21'],
            array_column($status, 'date')
        );
        $this->assertSame([false, false, false, false, true, true], array_column($status, 'sent'));

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])->assertCreated();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/weekly-requests/status')
            ->assertJsonPath('schedules.0.due_today', false)
            ->assertJsonPath('schedules.0.sent_today.reference_number', "WR-{$this->hospital->id}-0001");
    }

    public function test_request_days_before_the_schedule_existed_are_not_missed(): void
    {
        $this->scheduleWith($this->centre);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/weekly-requests/status')
            ->assertOk()
            ->assertJsonPath('schedules.0.recent', [])
            ->assertJsonPath('schedules.0.missed_count', 0)
            ->assertJsonPath('schedules.0.last_missed_day', null);
    }

    public function test_the_status_on_a_day_that_is_not_a_request_day(): void
    {
        $this->scheduleWith($this->centre, [2, 4]);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/weekly-requests/status')
            ->assertOk()
            ->assertJsonPath('schedules.0.is_request_day', false)
            ->assertJsonPath('schedules.0.due_today', false)
            ->assertJsonPath('schedules.0.next_request_day', '2026-10-06');
    }
}
