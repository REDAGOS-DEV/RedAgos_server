<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\DonationClearance;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Support\OperationalDay;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\DemoInventorySeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DemoInventorySeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CODES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BloodComponentSeeder::class);

        foreach (self::CODES as $code) {
            BloodType::factory()->code($code)->create();
        }

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->configureShelfLife($this->facility, [
            'Whole Blood' => 35, 'Packed RBC' => 42, 'Washed RBC' => 28, 'Platelet Concentrate' => 5,
            'Fresh Frozen Plasma' => 365, 'Cryoprecipitate' => 365, 'Cryosupernate' => 365,
        ]);
    }

    /**
     * @param  array<string, int>  $days
     */
    private function configureShelfLife(Facility $facility, array $days): void
    {
        foreach ($days as $name => $shelfLife) {
            FacilityBloodComponent::query()->create([
                'facility_id' => $facility->id,
                'component_id' => BloodComponent::where('name', $name)->value('id'),
                'shelf_life_days' => $shelfLife,
            ]);
        }
    }

    public function test_every_blood_type_has_available_stock(): void
    {
        $this->seed(DemoInventorySeeder::class);

        foreach (self::CODES as $code) {
            $this->assertTrue(
                BloodUnit::query()
                    ->where('facility_id', $this->facility->id)
                    ->where('status', BloodUnitStatus::Available)
                    ->whereHas('bloodType', fn ($q) => $q->where('code', $code))
                    ->exists(),
                "No available {$code} units were seeded."
            );
        }
    }

    public function test_every_component_the_facility_configured_is_stocked(): void
    {
        $this->seed(DemoInventorySeeder::class);

        $stocked = BloodUnit::query()->where('facility_id', $this->facility->id)->distinct()->pluck('component_id');

        $this->assertCount(7, $stocked);
    }

    public function test_seeded_units_are_cleared_and_in_date(): void
    {
        $this->seed(DemoInventorySeeder::class);

        $today = OperationalDay::todayAsDate();

        foreach (BloodUnit::all() as $unit) {
            // A released unit with no clearances is stock the dispatch gate
            // would refuse, so the chain behind each unit has to be whole.
            foreach (ClearanceKind::cases() as $kind) {
                $this->assertTrue(
                    DonationClearance::where('donation_id', $unit->donation_id)->where('kind', $kind->value)->exists(),
                    "Unit {$unit->id} has no {$kind->value} clearance."
                );
            }

            $this->assertGreaterThan($today, $unit->expiry_date->toDateString(), "Unit {$unit->id} was seeded expired.");
            $this->assertSame($unit->donation->donorProfile->blood_type_id, $unit->blood_type_id);
        }
    }

    public function test_re_running_it_does_not_duplicate(): void
    {
        $this->seed(DemoInventorySeeder::class);
        $units = BloodUnit::count();

        $this->seed(DemoInventorySeeder::class);

        $this->assertGreaterThan(0, $units);
        $this->assertSame($units, BloodUnit::count());
    }

    public function test_a_facility_without_shelf_lives_or_staff_is_left_alone(): void
    {
        $unconfigured = Facility::factory()->approved()->create();
        User::factory()->bloodCenterStaff($unconfigured)->create();

        $unstaffed = Facility::factory()->approved()->create();
        $this->configureShelfLife($unstaffed, ['Packed RBC' => 42]);

        $this->seed(DemoInventorySeeder::class);

        $this->assertSame(0, BloodUnit::where('facility_id', $unconfigured->id)->count());
        $this->assertSame(0, BloodUnit::where('facility_id', $unstaffed->id)->count());
    }
}
