<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\AllocationStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Repository\InventoryRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CompilesPostgresQueries;
use Tests\TestCase;

/**
 * A tagged bag that runs out of date before it goes is refused at dispatch.
 *
 * The nightly sweep gives such tags up, but only when it runs. Dispatch judges
 * the date itself, on locked rows, so a missed or late sweep cannot send an
 * expired bag out. Nothing is undone on refusal: the tag stays as it was.
 */
class ReleaseExpiryTest extends TestCase
{
    use CompilesPostgresQueries, LazilyRefreshDatabase;

    private User $staff;

    private Facility $centre;

    private Facility $hospital;

    private User $requester;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->centre, Department::Issuance)->create();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create([
            'name' => 'Packed Red Blood Cells',
            'price' => 0,
        ]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_a_tagged_unit_past_its_expiry_date_is_refused_with_unit_expired(): void
    {
        $request = $this->allocatedRequest(1);
        $unitId = $this->heldUnitIds($request)[0];
        $statusBefore = $request->status;

        $this->setExpiry($unitId, OperationalDay::today()->subDay()->toDateString());

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_expired')
            ->assertJsonPath('message', "Unit {$unitId} is past its expiry date and cannot be dispatched.");

        // Nothing moved: the bag is still tagged, the tag still stands, and
        // nothing was recorded as dispatched.
        $this->assertSame(BloodUnitStatus::Reserved, BloodUnit::query()->find($unitId)->status);

        $hold = RequestAllocation::query()->where('request_id', $request->id)->first();
        $this->assertSame(AllocationStatus::Allocated, $hold->status);
        $this->assertNull($hold->released_at);

        $this->assertSame($statusBefore, $request->fresh()->status);
        $this->assertFalse(AuditLog::query()->where('action', 'request.released')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'allocation.issued')->exists());
    }

    public function test_a_unit_expiring_today_is_still_dispatched(): void
    {
        $request = $this->allocatedRequest(1);
        $unitId = $this->heldUnitIds($request)[0];

        $this->setExpiry($unitId, OperationalDay::todayAsDate());

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk()
            ->assertJsonCount(1, 'released_units');

        $this->assertSame(BloodUnitStatus::Issued, BloodUnit::query()->find($unitId)->status);
    }

    public function test_one_expired_unit_refuses_the_whole_release(): void
    {
        $request = $this->allocatedRequest(2);
        [$first, $second] = $this->heldUnitIds($request);

        $this->setExpiry($second, OperationalDay::today()->subDay()->toDateString());

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_expired')
            ->assertJsonPath('message', "Unit {$second} is past its expiry date and cannot be dispatched.");

        // The in-date bag did not go out on its own either.
        $this->assertSame(2, BloodUnit::query()->whereIn('id', [$first, $second])->where('status', BloodUnitStatus::Reserved)->count());
        $this->assertSame(2, RequestAllocation::query()->where('request_id', $request->id)->where('status', AllocationStatus::Allocated)->count());
    }

    public function test_choosing_only_the_in_date_tags_still_dispatches_them(): void
    {
        $request = $this->allocatedRequest(2, askedFor: 2);
        [$first, $second] = $this->heldUnitIds($request);

        $this->setExpiry($second, OperationalDay::today()->subDay()->toDateString());

        $inDateHold = RequestAllocation::query()
            ->where('request_id', $request->id)
            ->where('unit_id', $first)
            ->value('id');

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release", ['allocation_ids' => [$inDateHold]])
            ->assertOk()
            ->assertJsonCount(1, 'released_units');

        $this->assertSame(BloodUnitStatus::Issued, BloodUnit::query()->find($first)->status);
        // The expired bag is left exactly as it was: still tagged, not returned.
        $this->assertSame(BloodUnitStatus::Reserved, BloodUnit::query()->find($second)->status);
        $this->assertSame(
            AllocationStatus::Allocated,
            RequestAllocation::query()->where('unit_id', $second)->value('status')
        );
    }

    public function test_the_day_is_the_operational_day_in_manila_not_the_utc_date(): void
    {
        // 17:00 UTC on 10 November is 01:00 on 11 November in Manila: before the
        // 00:30 sweep has had its chance to matter, and on a different date
        // from the UTC one.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 17:00:00', 'UTC'));
        $this->assertSame('2026-11-11', OperationalDay::todayAsDate());

        $request = $this->allocatedRequest(1);
        $unitId = $this->heldUnitIds($request)[0];

        // In date by the UTC calendar, out of date by the operational one.
        $this->setExpiry($unitId, '2026-11-10');

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_expired');

        // Corrected to a bag that expires on the operational today, it goes.
        $this->setExpiry($unitId, '2026-11-11');

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();

        $this->assertSame(BloodUnitStatus::Issued, BloodUnit::query()->find($unitId)->status);
    }

    public function test_the_units_are_locked_in_id_order_before_their_dates_are_judged(): void
    {
        $queries = $this->compiledOnPostgres(
            fn () => app(InventoryRepository::class)->lockUnits(['B-02', 'A-01'], $this->centre->id)
        );

        $this->assertStringContainsString('order by "id" asc', $queries[0]['query']);
        $this->assertStringEndsWith('for update', $queries[0]['query']);
    }

    /**
     * A request with this many units tagged for it, all in date when tagged.
     */
    private function allocatedRequest(int $units, ?int $askedFor = null): BloodRequest
    {
        $request = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $askedFor ?? $units)
            ->create();

        $this->stock($units);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => $units])
            ->assertOk();

        return $request->fresh();
    }

    /**
     * The ids of the units tagged for a request, in id order.
     *
     * @return array<int, string>
     */
    private function heldUnitIds(BloodRequest $request): array
    {
        return RequestAllocation::query()
            ->where('request_id', $request->id)
            ->where('status', AllocationStatus::Allocated)
            ->orderBy('unit_id')
            ->pluck('unit_id')
            ->all();
    }

    /**
     * A bag running out of date on the shelf after it was tagged.
     */
    private function setExpiry(string $unitId, string $date): void
    {
        BloodUnit::query()->whereKey($unitId)->update(['expiry_date' => $date]);
    }

    private function stock(int $count): void
    {
        $donation = Donation::factory()->create([
            'facility_id' => $this->centre->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->count($count)->create([
            'facility_id' => $this->centre->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
        ]);
    }
}
