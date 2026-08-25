<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * What staff may do to a unit already on the shelf.
 *
 * Two cases here are worth naming. Discarding an expired unit must not erase
 * when it expired (D6), and correcting an expired unit's date must bring it
 * back — the escape hatch for a mistyped year the sweep has already acted on,
 * which discard-and-re-record cannot rescue because the bag's printed number is
 * already taken by the discarded row.
 */
class InventoryLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private int $facilityId;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->bloodCenterStaff()->create();
        $this->facilityId = $this->staff->facility_id;

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_a_storage_location_can_be_corrected(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01', 'storage_location' => 'Cold Storage A-1']);

        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['storage_location' => 'Freezer B'])
            ->assertOk()
            ->assertJsonPath('unit.storage_location', 'Freezer B');

        $this->assertDatabaseHas('blood_units', ['id' => 'AVAIL-01', 'storage_location' => 'Freezer B']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.updated',
            'actor_id' => $this->staff->id,
            'auditable_id' => 'AVAIL-01',
        ]);
    }

    public function test_an_expiry_date_can_be_corrected(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01', 'expiry_date' => $this->inDays(5)]);

        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['expiry_date' => $this->inDays(40)])
            ->assertOk()
            ->assertJsonPath('unit.expiry_date', $this->inDays(40))
            ->assertJsonPath('unit.status', BloodUnitStatus::Available->value);
    }

    public function test_an_update_that_sends_nothing_is_refused(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, [])
            ->assertStatus(422);
    }

    public function test_a_correction_to_a_past_expiry_date_is_refused(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['expiry_date' => $this->inDays(-1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_date');
    }

    public function test_a_reserved_or_issued_unit_cannot_be_edited(): void
    {
        foreach ([BloodUnitStatus::Reserved, BloodUnitStatus::Issued] as $status) {
            $unit = $this->makeUnit(['id' => 'HELD-'.$status->value, 'status' => $status]);

            $this->actingAs($this->staff)
                ->patchJson('/api/blood-center/inventory/'.$unit->id, ['storage_location' => 'Freezer B'])
                ->assertStatus(409)
                ->assertJsonPath('code', 'unit_not_editable');
        }
    }

    public function test_a_discarded_unit_cannot_be_edited(): void
    {
        $unit = $this->makeUnit(['id' => 'GONE-01', 'status' => BloodUnitStatus::Discarded]);

        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['expiry_date' => $this->inDays(30)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_editable');
    }

    public function test_another_facilitys_unit_is_not_found(): void
    {
        $otherFacility = Facility::factory()->approved()->create();
        $unit = $this->makeUnit(['id' => 'THEIRS-01'], $otherFacility->id);

        // A 404 rather than a 403: unit ids are read off a printed bag and are
        // guessable, so the refusal must not confirm the id exists.
        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['storage_location' => 'Freezer B'])
            ->assertNotFound()
            ->assertJsonPath('code', 'unit_not_found');

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', ['reason' => 'Seal broken'])
            ->assertNotFound()
            ->assertJsonPath('code', 'unit_not_found');
    }

    public function test_a_unit_can_be_discarded_with_a_reason(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', ['reason' => 'Seal broken during handling'])
            ->assertOk()
            ->assertJsonPath('unit.status', BloodUnitStatus::Discarded->value)
            ->assertJsonPath('unit.discard_reason', 'Seal broken during handling');

        $unit->refresh();

        $this->assertSame(BloodUnitStatus::Discarded, $unit->status);
        $this->assertSame('Seal broken during handling', $unit->discard_reason);
        $this->assertNotNull($unit->discarded_at);
        // Discarded in date, so there is no expiry event to record.
        $this->assertNull($unit->expired_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.discarded',
            'actor_id' => $this->staff->id,
            'auditable_id' => 'AVAIL-01',
        ]);
    }

    public function test_a_discarded_unit_leaves_the_available_counts(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', ['reason' => 'Seal broken'])
            ->assertOk();

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonPath('totals.available', 0)
            ->assertJsonPath('totals.discarded', 1);
    }

    public function test_a_discard_requires_a_reason(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_reserved_unit_cannot_be_discarded_here(): void
    {
        $unit = $this->makeUnit(['id' => 'HELD-01', 'status' => BloodUnitStatus::Reserved]);

        // Releasing an allocation is the allocation module's business; a centre
        // cannot dispose of blood a hospital is holding.
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', ['reason' => 'Seal broken'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_discardable');
    }

    public function test_a_second_discard_is_refused_rather_than_treated_as_idempotent(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', ['reason' => 'Seal broken during handling'])
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', ['reason' => 'Clicked the wrong row'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_discardable');

        // The point of refusing rather than accepting it twice: a mis-clicked
        // reason cannot overwrite the one that was actually recorded.
        $this->assertSame('Seal broken during handling', $unit->refresh()->discard_reason);
    }

    public function test_discarding_an_expired_unit_keeps_when_it_expired(): void
    {
        $unit = $this->makeUnit(['id' => 'EXP-01'], null, ['expired']);
        $expiredAt = $unit->expired_at;

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory/'.$unit->id.'/discard', ['reason' => 'Past expiry, disposed of in batch'])
            ->assertOk()
            ->assertJsonPath('unit.status', BloodUnitStatus::Discarded->value);

        $unit->refresh();

        // D6, and the regression it exists to prevent: without expired_at,
        // "expired on the shelf, then disposed of" and "disposed of while still
        // in date because the seal broke" become the same row, and the expiry
        // count decays as the disposal backlog is worked through.
        $this->assertNotNull($unit->expired_at);
        $this->assertTrue($expiredAt->equalTo($unit->expired_at));
        $this->assertNotNull($unit->discarded_at);
    }

    public function test_correcting_an_expired_units_date_returns_it_to_available(): void
    {
        $unit = $this->makeUnit(['id' => 'EXP-01'], null, ['expired']);
        $mistypedDate = $unit->expiry_date->toDateString();

        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['expiry_date' => $this->inDays(30)])
            ->assertOk()
            ->assertJsonPath('unit.status', BloodUnitStatus::Available->value)
            ->assertJsonPath('unit.expiry_date', $this->inDays(30));

        $unit->refresh();

        $this->assertSame(BloodUnitStatus::Available, $unit->status);
        $this->assertNull($unit->expired_at);

        // Its own audit action rather than inventory.updated: an un-expiry is
        // the one edit here worth being able to find later.
        $entry = AuditLog::query()->where('action', 'inventory.reinstated')->first();

        $this->assertNotNull($entry);
        $this->assertSame('EXP-01', $entry->auditable_id);
        $this->assertSame($this->staff->id, $entry->actor_id);
        $this->assertSame($mistypedDate, $entry->context['previous_expiry_date']);
        $this->assertSame($this->inDays(30), $entry->context['expiry_date']);
    }

    public function test_an_expired_unit_may_not_have_its_storage_location_changed(): void
    {
        $unit = $this->makeUnit(['id' => 'EXP-01'], null, ['expired']);

        // Otherwise "correct the typo" and "quietly move expired stock" are the
        // same request.
        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, [
                'expiry_date' => $this->inDays(30),
                'storage_location' => 'Freezer B',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_editable');

        $this->assertSame(BloodUnitStatus::Expired, $unit->refresh()->status);
    }

    public function test_an_expired_unit_cannot_be_reinstated_to_a_date_already_past(): void
    {
        $unit = $this->makeUnit(['id' => 'EXP-01'], null, ['expired']);

        // The identical rule intake uses, which is what makes the reinstate path
        // safe: a unit cannot come back onto the shelf already expired.
        $this->actingAs($this->staff)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['expiry_date' => $this->inDays(-1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_date');

        $this->assertSame(BloodUnitStatus::Expired, $unit->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, string>  $states
     */
    private function makeUnit(array $overrides = [], ?int $facilityId = null, array $states = []): BloodUnit
    {
        $facilityId ??= $this->facilityId;

        $donation = Donation::factory()->create([
            'facility_id' => $facilityId,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        $factory = BloodUnit::factory();

        foreach ($states as $state) {
            $factory = $factory->{$state}();
        }

        return $factory->create([
            'facility_id' => $facilityId,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            ...$overrides,
        ]);
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
