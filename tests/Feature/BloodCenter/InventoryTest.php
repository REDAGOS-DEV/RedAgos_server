<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryTest extends TestCase
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

        // firstOrCreate, and the donor profile is pinned to it: DonorProfileFactory
        // picks a random code from the same eight, so letting it run free here
        // collides with the unique index about one time in eight.
        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_lists_only_the_callers_own_facility_stock(): void
    {
        $this->makeUnit(['id' => 'MINE-01']);

        $otherFacility = Facility::factory()->approved()->create();
        $this->makeUnit(['id' => 'THEIRS-01'], $otherFacility->id);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame(['MINE-01'], $ids);
    }

    public function test_units_are_ordered_first_expiring_first(): void
    {
        $this->makeUnit(['id' => 'FAR-01', 'expiry_date' => $this->inDays(10)]);
        $this->makeUnit(['id' => 'SOON-01', 'expiry_date' => $this->inDays(2)]);
        $this->makeUnit(['id' => 'MID-01', 'expiry_date' => $this->inDays(5)]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory')
            ->assertOk();

        $this->assertSame(['SOON-01', 'MID-01', 'FAR-01'], array_column($response->json('data'), 'id'));
    }

    public function test_the_status_filter_narrows_the_listing(): void
    {
        $this->makeUnit(['id' => 'AVAIL-01']);
        $this->makeUnit(['id' => 'GONE-01', 'status' => BloodUnitStatus::Discarded]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?status=discarded')
            ->assertOk();

        $this->assertSame(['GONE-01'], array_column($response->json('data'), 'id'));
    }

    public function test_the_blood_type_and_component_filters_narrow_the_listing(): void
    {
        $otherType = BloodType::firstOrCreate(['code' => 'AB-'], ['label' => 'AB-']);
        $otherComponent = BloodComponent::factory()->create(['name' => 'Platelet Concentrate']);

        $this->makeUnit(['id' => 'MATCH-01']);
        $this->makeUnit(['id' => 'OTHERTYPE-01', 'blood_type_id' => $otherType->id]);
        $this->makeUnit(['id' => 'OTHERCOMP-01', 'component_id' => $otherComponent->id]);

        $byType = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?blood_type_id='.$this->bloodType->id)
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['MATCH-01', 'OTHERCOMP-01'],
            array_column($byType->json('data'), 'id')
        );

        $byComponent = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?component_id='.$this->component->id)
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['MATCH-01', 'OTHERTYPE-01'],
            array_column($byComponent->json('data'), 'id')
        );
    }

    public function test_the_expiring_within_days_filter_narrows_the_listing(): void
    {
        $this->makeUnit(['id' => 'SOON-01', 'expiry_date' => $this->inDays(2)]);
        $this->makeUnit(['id' => 'LATER-01', 'expiry_date' => $this->inDays(20)]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?expiring_within_days=3')
            ->assertOk();

        $this->assertSame(['SOON-01'], array_column($response->json('data'), 'id'));
    }

    public function test_the_storage_location_filter_narrows_the_listing(): void
    {
        $this->makeUnit(['id' => 'FRIDGE-01', 'storage_location' => 'Cold Storage A-1']);
        $this->makeUnit(['id' => 'FREEZER-01', 'storage_location' => 'Freezer B']);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?storage_location=Freezer%20B')
            ->assertOk();

        $this->assertSame(['FREEZER-01'], array_column($response->json('data'), 'id'));
    }

    public function test_days_remaining_is_zero_for_a_unit_expiring_today(): void
    {
        $this->makeUnit(['id' => 'TODAY-01', 'expiry_date' => $this->inDays(0)]);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.days_remaining', 0);
    }

    public function test_the_summary_counts_match_the_units_recorded(): void
    {
        $this->makeUnit(['id' => 'A-01']);
        $this->makeUnit(['id' => 'A-02']);
        $this->makeUnit(['id' => 'E-01', 'status' => BloodUnitStatus::Expired]);
        $this->makeUnit(['id' => 'D-01', 'status' => BloodUnitStatus::Discarded]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk();

        $this->assertSame(2, $response->json('totals.available'));
        $this->assertSame(1, $response->json('totals.expired'));
        $this->assertSame(1, $response->json('totals.discarded'));
        // Projected from the enum, so a status with no stock still appears.
        $this->assertSame(0, $response->json('totals.reserved'));

        $this->assertSame(
            [['blood_type_id' => $this->bloodType->id, 'code' => 'O+', 'available' => 2]],
            $response->json('by_blood_type')
        );
    }

    public function test_the_summary_counts_stock_close_to_its_date(): void
    {
        $this->makeUnit(['id' => 'SOON-01', 'expiry_date' => $this->inDays(2)]);
        $this->makeUnit(['id' => 'WEEK-01', 'expiry_date' => $this->inDays(6)]);
        $this->makeUnit(['id' => 'LATER-01', 'expiry_date' => $this->inDays(40)]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk();

        $this->assertSame(1, $response->json('near_expiry.within_3_days'));
        $this->assertSame(2, $response->json('near_expiry.within_7_days'));
    }

    public function test_the_summary_unions_configured_and_recorded_storage_locations(): void
    {
        $this->makeUnit(['id' => 'CUSTOM-01', 'storage_location' => 'Back Room Chiller']);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk();

        $locations = $response->json('storage_locations');

        $this->assertContains('Back Room Chiller', $locations);
        $this->assertContains('Cold Storage A-1', $locations);
        // Unioned, not duplicated, when a recorded value is also configured.
        $this->assertSame(count($locations), count(array_unique($locations)));
    }

    public function test_the_summary_is_facility_isolated(): void
    {
        $otherFacility = Facility::factory()->approved()->create();
        $this->makeUnit(['id' => 'THEIRS-01'], $otherFacility->id);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonPath('totals.available', 0);
    }

    public function test_summary_is_not_swallowed_by_the_unit_route(): void
    {
        // /inventory/{unit} is a string parameter and would match 'summary'
        // outright if it were declared first.
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonStructure(['totals', 'by_blood_type', 'by_component', 'near_expiry', 'as_of']);
    }

    public function test_the_recent_sort_lists_the_newest_bags_first(): void
    {
        $this->makeUnit(['id' => 'OLD-01', 'expiry_date' => $this->inDays(2), 'created_at' => now()->subDays(3)]);
        $this->makeUnit(['id' => 'NEW-01', 'expiry_date' => $this->inDays(30), 'created_at' => now()]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?sort=recent')
            ->assertOk();

        $this->assertSame(['NEW-01', 'OLD-01'], array_column($response->json('data'), 'id'));
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?sort=random')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    public function test_the_search_matches_a_storage_location_in_any_case(): void
    {
        $this->makeUnit(['id' => 'FRIDGE-01', 'storage_location' => 'Cold Storage A-1']);
        $this->makeUnit(['id' => 'FREEZER-01', 'storage_location' => 'Freezer B']);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?search=cold%20storage')
            ->assertOk();

        $this->assertSame(['FRIDGE-01'], array_column($response->json('data'), 'id'));

        $byId = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory?search=freezer-')
            ->assertOk();

        $this->assertSame(['FREEZER-01'], array_column($byId->json('data'), 'id'));
    }

    public function test_a_listed_unit_carries_its_collection_date(): void
    {
        $unit = $this->makeUnit(['id' => 'DATED-01']);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.collected_at', $unit->donation->donation_date->toIso8601String());
    }

    public function test_the_summary_counts_units_issued_today_by_this_facility_only(): void
    {
        $today = $this->makeUnit(['id' => 'TODAY-01', 'status' => BloodUnitStatus::Issued]);
        $yesterday = $this->makeUnit(['id' => 'YDAY-01', 'status' => BloodUnitStatus::Issued]);
        $held = $this->makeUnit(['id' => 'HELD-01', 'status' => BloodUnitStatus::Reserved]);

        $otherFacility = Facility::factory()->approved()->create();
        $theirs = $this->makeUnit(['id' => 'THEIRS-01', 'status' => BloodUnitStatus::Issued], $otherFacility->id);

        RequestAllocation::factory()->released()->create(['unit_id' => $today->id]);
        RequestAllocation::factory()->released()->create([
            'unit_id' => $yesterday->id,
            'released_at' => OperationalDay::boundsFor()[0]->subMinute(),
        ]);
        RequestAllocation::factory()->create(['unit_id' => $held->id]);
        RequestAllocation::factory()->released()->create(['unit_id' => $theirs->id]);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonPath('issued_today', 1);
    }

    public function test_the_summary_counts_units_released_to_stock_this_week(): void
    {
        $this->makeUnit(['id' => 'FRESH-01', 'released_at' => now()->subDays(2)]);
        $this->makeUnit(['id' => 'STALE-01', 'released_at' => now()->subDays(9)]);
        $this->makeUnit(['id' => 'HELD-01', 'status' => BloodUnitStatus::Quarantined, 'released_at' => null]);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/summary')
            ->assertOk()
            ->assertJsonPath('released_last_7_days', 1);
    }

    public function test_the_activity_lists_this_facilitys_unit_movements_newest_first(): void
    {
        $unit = $this->makeUnit(['id' => 'MOVE-01']);

        $this->audit('inventory.recorded', $unit, now()->subHours(3));
        $this->audit('allocation.reserved', $unit, now()->subHour(), $this->staff);
        // Audited on the same unit, but the hospital's act, not this centre's.
        $this->audit('hospital_inventory.received', $unit, now());

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/activity')
            ->assertOk();

        $this->assertSame(['reserved', 'booked_in'], array_column($response->json('data'), 'kind'));
        $response
            ->assertJsonPath('data.0.unit_id', 'MOVE-01')
            ->assertJsonPath('data.0.blood_type', 'O+')
            ->assertJsonPath('data.0.component', 'Packed RBC')
            ->assertJsonPath('data.0.actor', trim($this->staff->first_name.' '.$this->staff->last_name))
            // The sweep and other system acts have no one behind them.
            ->assertJsonPath('data.1.actor', null);
    }

    public function test_the_activity_is_facility_isolated(): void
    {
        $otherFacility = Facility::factory()->approved()->create();
        $theirs = $this->makeUnit(['id' => 'THEIRS-01'], $otherFacility->id);

        $this->audit('inventory.discarded', $theirs, now());

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/activity')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_activity_narrows_to_one_unit(): void
    {
        $mine = $this->makeUnit(['id' => 'MINE-01']);
        $other = $this->makeUnit(['id' => 'OTHER-01']);

        $this->audit('inventory.recorded', $mine, now()->subMinute());
        $this->audit('inventory.recorded', $other, now());

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/inventory/activity?unit_id=MINE-01')
            ->assertOk();

        $this->assertSame(['MINE-01'], array_column($response->json('data'), 'unit_id'));
    }

    public function test_an_unapproved_facility_is_refused(): void
    {
        $facility = Facility::factory()->rejected()->create();
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/inventory')
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_approved');
    }

    public function test_an_unverified_account_is_refused(): void
    {
        $staff = User::factory()->unverified()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->getJson('/api/blood-center/inventory')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_a_donor_is_refused(): void
    {
        // The role gate is what is under test, so this deliberately skips
        // ->donor(), which would build a profile and another random blood type.
        $this->actingAs(User::factory()->withRole(RoleName::Donor)->create())
            ->getJson('/api/blood-center/inventory')
            ->assertForbidden();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/blood-center/inventory')->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeUnit(array $overrides = [], ?int $facilityId = null): BloodUnit
    {
        $facilityId ??= $this->facilityId;

        $donation = Donation::factory()->create([
            'facility_id' => $facilityId,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        return BloodUnit::factory()->create([
            'facility_id' => $facilityId,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            ...$overrides,
        ]);
    }

    /**
     * Write an audit row about a unit, at a given moment.
     */
    private function audit(string $action, BloodUnit $unit, Carbon $at, ?User $actor = null): void
    {
        $entry = AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => BloodUnit::class,
            'auditable_id' => $unit->id,
        ]);

        $entry->forceFill(['created_at' => $at])->save();
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
