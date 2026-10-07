<?php

namespace Tests\Feature\Receiving;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\ReplenishmentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\Feature\Receiving\Concerns\SendsWeeklyRequests;
use Tests\TestCase;

/**
 * A hospital blood bank's request days, set by the blood bank itself, per centre.
 *
 * The days decide whether a weekly request may be sent, so every change is
 * audited with the days before and after, and a schedule can only name a
 * centre a request could be sent to.
 */
class ReplenishmentScheduleTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase, SendsWeeklyRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_a_blood_bank_sets_its_request_days_for_a_centre(): void
    {
        $this->save($this->centre, [5, 1, 3])
            ->assertOk()
            ->assertJsonPath('schedule.target_facility.id', $this->centre->id)
            ->assertJsonPath('schedule.target_facility.name', 'Davao Blood Center')
            ->assertJsonPath('schedule.days_of_week', [1, 3, 5])
            ->assertJsonPath('schedule.days_label', 'Mon, Wed, Fri');

        $schedule = ReplenishmentSchedule::query()->sole();
        $this->assertSame($this->hospital->id, $schedule->facility_id);
        $this->assertSame($this->requester->id, $schedule->updated_by);

        $audit = AuditLog::query()->where('action', 'replenishment_schedule.saved')->sole();
        $this->assertNull($audit->context['previous_days']);
        $this->assertSame([1, 3, 5], $audit->context['days_of_week']);
    }

    public function test_changing_the_days_replaces_them_and_records_what_they_were(): void
    {
        $this->save($this->centre, [1, 3, 5])->assertOk();
        $this->save($this->centre, [2, 4])->assertOk()->assertJsonPath('schedule.days_label', 'Tue, Thu');

        $this->assertSame([2, 4], ReplenishmentSchedule::query()->sole()->weekdays());

        $audit = AuditLog::query()->where('action', 'replenishment_schedule.saved')->latest('id')->firstOrFail();
        $this->assertSame([1, 3, 5], $audit->context['previous_days']);
        $this->assertSame([2, 4], $audit->context['days_of_week']);
    }

    public function test_a_change_makes_today_a_request_day_at_once(): void
    {
        $this->travelToMonday();
        $this->save($this->centre, [2])->assertOk();

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])->assertStatus(409)->assertJsonPath('code', 'not_a_request_day');

        $this->save($this->centre, [1, 2])->assertOk();

        $this->sendWeekly([[$this->bloodType, $this->prbc, 2]])->assertCreated();
    }

    public function test_each_centre_keeps_its_own_days_and_only_the_blood_banks_own_are_listed(): void
    {
        $this->save($this->centre, [1, 3, 5])->assertOk();
        $this->save($this->otherCentre, [2])->assertOk();

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        ReplenishmentSchedule::factory()->between($otherHospital, $this->centre)->on([6])->create();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/replenishment-schedules')
            ->assertOk()
            ->assertJsonCount(2, 'schedules')
            ->assertJsonPath('schedules.0.days_label', 'Mon, Wed, Fri')
            ->assertJsonPath('schedules.1.days_label', 'Tue');
    }

    public function test_invalid_days_are_refused(): void
    {
        $cases = [
            'no days' => [[], 'days_of_week'],
            'day zero' => [[0], 'days_of_week.0'],
            'day eight' => [[8], 'days_of_week.0'],
            'a day twice' => [[1, 1], 'days_of_week.0'],
            'a day by name' => [['monday'], 'days_of_week.0'],
        ];

        foreach ($cases as [$days, $field]) {
            $this->save($this->centre, $days)->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseCount('replenishment_schedules', 0);
    }

    public function test_a_schedule_can_only_name_a_centre_a_request_could_go_to(): void
    {
        $otherBank = Facility::factory()->bloodBank()->approved()->create();
        $pending = Facility::factory()->pendingApproval()->create();

        foreach ([$this->hospital, $otherBank, $pending] as $facility) {
            $this->save($facility, [1])->assertStatus(422)->assertJsonValidationErrors(['target_facility_id']);
        }

        $this->actingAs($this->requester)
            ->putJson('/api/hospital/replenishment-schedules/999999', ['days_of_week' => [1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_facility_id']);

        $this->assertDatabaseCount('replenishment_schedules', 0);
    }

    public function test_a_blood_bank_removes_its_request_days_for_a_centre(): void
    {
        $this->save($this->centre, [1, 3])->assertOk();

        $this->actingAs($this->requester)
            ->deleteJson("/api/hospital/replenishment-schedules/{$this->centre->id}")
            ->assertOk();

        $this->assertDatabaseCount('replenishment_schedules', 0);
        $this->assertSame(
            [1, 3],
            AuditLog::query()->where('action', 'replenishment_schedule.deleted')->sole()->context['previous_days']
        );

        $this->actingAs($this->requester)
            ->deleteJson("/api/hospital/replenishment-schedules/{$this->centre->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'schedule_not_found');
    }

    public function test_another_blood_banks_schedule_cannot_be_removed(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        ReplenishmentSchedule::factory()->between($otherHospital, $this->centre)->create();

        $this->actingAs($this->requester)
            ->deleteJson("/api/hospital/replenishment-schedules/{$this->centre->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('replenishment_schedules', 1);
    }

    public function test_a_blood_centre_account_cannot_keep_request_days(): void
    {
        $this->actingAs($this->issuance)
            ->putJson("/api/hospital/replenishment-schedules/{$this->otherCentre->id}", ['days_of_week' => [1]])
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/hospital/replenishment-schedules')
            ->assertForbidden();
    }

    /**
     * Set the scenario hospital's request days for a facility.
     *
     * @param  array<int, mixed>  $days
     */
    private function save(Facility $facility, array $days): TestResponse
    {
        return $this->actingAs($this->requester)
            ->putJson("/api/hospital/replenishment-schedules/{$facility->id}", ['days_of_week' => $days]);
    }
}
