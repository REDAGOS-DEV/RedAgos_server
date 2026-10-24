<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Enums\UrgencyLevel;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The forecast for the weeks ahead, each department its own part.
 */
class DemandForecastTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 21 October 2026: the history is the twelve weeks to Sunday the 18th.
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', config('blood_center.timezone')));
        $this->centre = Facility::factory()->approved()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function staff(StaffRole $role): User
    {
        return User::factory()->bloodCenterStaff($this->centre, $role)->create();
    }

    public function test_each_department_sees_its_own_part(): void
    {
        $sections = fn (User $user): array => $this->actingAs($user)->getJson('/api/blood-center/reports/forecast')->assertOk()->json('sections');

        $this->assertSame(['demand'], $sections($this->staff(StaffRole::InventoryControlOfficer)));
        $this->assertSame(['collection'], $sections($this->staff(StaffRole::ScreeningPhysician)));
        $this->assertSame(['processing'], $sections($this->staff(StaffRole::ComponentTechnologist)));
        $this->assertSame(['testing'], $sections($this->staff(StaffRole::SerologyTechnologist)));
        $this->assertSame(['billing'], $sections($this->staff(StaffRole::BillingClerk)));
        $this->assertSame(
            ['demand', 'collection', 'processing', 'testing', 'billing'],
            $sections(User::factory()->bloodCenterSupervisor($this->centre)->create())
        );

        $this->actingAs(User::factory()->bloodCenterAdmin($this->centre)->create())
            ->getJson('/api/blood-center/reports/forecast')
            ->assertForbidden();
    }

    public function test_demand_is_weighted_to_recent_weeks_and_set_against_the_stock(): void
    {
        $type = BloodType::query()->first() ?? BloodType::factory()->create();
        $component = BloodComponent::query()->first() ?? BloodComponent::factory()->create();
        $hospital = Facility::factory()->approved()->create();

        // Five units a week, in each of the last four weeks; one an emergency.
        foreach (['2026-09-23', '2026-09-30', '2026-10-07', '2026-10-14'] as $i => $day) {
            $request = BloodRequest::factory()->create([
                'facility_id' => $hospital->id,
                'target_facility_id' => $this->centre->id,
                'blood_type_id' => $type->id,
                'request_date' => Carbon::parse("{$day} 09:00", config('blood_center.timezone')),
                'urgency_level' => $i === 3 ? UrgencyLevel::Emergency : UrgencyLevel::Routine,
            ]);
            $request->items()->delete();
            BloodRequestItem::factory()->create(['request_id' => $request->id, 'component_id' => $component->id, 'blood_type_id' => $type->id, 'quantity' => 5]);
        }
        // Three on the shelf.
        BloodUnit::factory()->count(3)->create([
            'facility_id' => $this->centre->id,
            'component_id' => $component->id,
            'blood_type_id' => $type->id,
            'status' => BloodUnitStatus::Available,
            'expiry_date' => '2026-11-30',
        ]);

        $demand = $this->actingAs($this->staff(StaffRole::InventoryControlOfficer))
            ->getJson('/api/blood-center/reports/forecast')
            ->assertOk()
            ->json('demand');

        // (9 + 10 + 11 + 12) x 5 / 78 = 2.7 a week; 3 units last 1.1 weeks.
        $row = $demand['rows'][0];
        $this->assertSame($type->code, $row['blood_type']);
        $this->assertEquals(2.7, $row['per_week']);
        $this->assertSame(3, $row['stock']);
        $this->assertEquals(1.1, $row['weeks_of_cover']);
        $this->assertSame('watch', $row['status']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 5, 5, 5, 5], $demand['total']['series']);
        $this->assertSame(1, $demand['emergency']['requests']);
        $this->assertEquals(25.0, $demand['emergency']['share']);
    }

    public function test_collection_sets_the_donors_needed_against_those_collected(): void
    {
        $type = BloodType::query()->first() ?? BloodType::factory()->create();
        $component = BloodComponent::query()->first() ?? BloodComponent::factory()->create();

        $request = BloodRequest::factory()->create([
            'target_facility_id' => $this->centre->id,
            'blood_type_id' => $type->id,
            'request_date' => Carbon::parse('2026-10-14 09:00', config('blood_center.timezone')),
        ]);
        $request->items()->delete();
        BloodRequestItem::factory()->create(['request_id' => $request->id, 'component_id' => $component->id, 'blood_type_id' => $type->id, 'quantity' => 13]);
        Donation::factory()->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-10-13 02:00', 'status' => DonationStatus::Completed]);

        $collection = $this->actingAs($this->staff(StaffRole::ScreeningPhysician))
            ->getJson('/api/blood-center/reports/forecast')
            ->assertOk()
            ->json('collection');

        // 13 units in the latest week: 13 x 12 / 78 = 2 donors a week needed.
        $this->assertEquals(2.0, $collection['needed_per_week']);
        $this->assertEquals(0.2, $collection['donations']['per_week']);
        $this->assertSame($type->code, $collection['rows'][0]['blood_type']);
        $this->assertSame('short', $collection['rows'][0]['status']);
    }
}
