<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Daily Blood Stock Inventory, laid out as SNBC-Mindanao's sheet.
 *
 * What it counts (issuable stock, nothing else), how it lays the counts out
 * (Rh split, ABO order, the sheet's three tables and the extras), and which
 * components are broken down by expiry date (by each facility's own shelf
 * life, against its Packed RBC).
 */
class StockReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $issuance;

    /** @var array<string, BloodComponent> */
    private array $components = [];

    /** @var array<string, BloodType> */
    private array $types = [];

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create(['name' => 'Sub-National Blood Center - Mindanao']);
        $this->issuance = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create([
            'first_name' => 'John',
            'last_name' => 'Abarsolo',
            'position' => 'RMT',
        ]);

        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $code) {
            $this->types[$code] = BloodType::firstOrCreate(['code' => $code], ['label' => $code]);
        }

        $shelfLife = [
            'Packed RBC' => 42,
            'Platelet Concentrate' => 5,
            'Fresh Frozen Plasma' => 365,
            'Cryoprecipitate' => 365,
            'Cryosupernate' => 365,
            // Stocked, but nobody has set its shelf life at this centre.
            'Washed RBC' => null,
        ];

        foreach ($shelfLife as $name => $days) {
            $this->components[$name] = BloodComponent::factory()->create(['name' => $name]);

            if ($days !== null) {
                FacilityBloodComponent::create([
                    'facility_id' => $this->facility->id,
                    'component_id' => $this->components[$name]->id,
                    'shelf_life_days' => $days,
                ]);
            }
        }

        // The donor is pinned to an existing type: DonorProfileFactory would
        // otherwise mint a random one, and all eight already exist here.
        $donor = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->types['O+']->id,
        ]);

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $donor->donor_id,
        ]);
    }

    private function unit(string $component, string $type, int $inDays, array $overrides = []): BloodUnit
    {
        return BloodUnit::factory()->create([
            'id' => 'RA-'.Str::upper(Str::random(10)),
            'facility_id' => $this->facility->id,
            'component_id' => $this->components[$component]->id,
            'blood_type_id' => $this->types[$type]->id,
            'donation_id' => $this->donation->id,
            'expiry_date' => OperationalDay::today()->addDays($inDays)->toDateString(),
            'status' => BloodUnitStatus::Available,
            ...$overrides,
        ]);
    }

    private function report(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->issuance)->getJson('/api/blood-center/inventory/stock-report');
    }

    /**
     * @return array<string, mixed>
     */
    private function table(array $report, string $key): array
    {
        return collect($report['tables'])->firstWhere('key', $key)
            ?? $this->fail("No {$key} table in the report.");
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(array $report, string $table, string $type, string $component): array
    {
        $row = collect($this->table($report, $table)['rows'])->firstWhere('blood_type', $type)
            ?? $this->fail("No {$type} row in {$table}.");

        return $row['cells'][$this->components[$component]->id];
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }

    // --- What counts ---------------------------------------------------------

    public function test_only_issuable_units_at_this_facility_count(): void
    {
        $this->unit('Packed RBC', 'A+', 10);
        $this->unit('Packed RBC', 'A+', 10);
        $this->unit('Packed RBC', 'A+', 20);

        // None of these is stock that may be issued.
        $this->unit('Packed RBC', 'A+', 10, ['status' => BloodUnitStatus::Reserved]);
        $this->unit('Packed RBC', 'A+', 10, ['status' => BloodUnitStatus::Issued]);
        $this->unit('Packed RBC', 'A+', 10, ['status' => BloodUnitStatus::Discarded]);
        // Past its date but not yet swept: still says available.
        $this->unit('Packed RBC', 'A+', -1);
        // Another centre's.
        $this->unit('Packed RBC', 'A+', 10, ['facility_id' => Facility::factory()->approved()->create()->id]);

        $cell = $this->cell($this->report()->assertOk()->json(), 'rh_positive_prbc', 'A+', 'Packed RBC');

        $this->assertSame(3, $cell['total']);
        $this->assertSame(
            [[$this->inDays(10), 2], [$this->inDays(20), 1]],
            array_map(fn (array $e): array => [$e['date'], $e['units']], $cell['by_expiry'])
        );
    }

    public function test_a_unit_expiring_today_still_counts_and_is_flagged(): void
    {
        $this->unit('Packed RBC', 'O+', 0);
        $this->unit('Platelet Concentrate', 'O+', 1);

        $report = $this->report()->assertOk()->json();

        $prbc = $this->cell($report, 'rh_positive_prbc', 'O+', 'Packed RBC');
        $this->assertTrue($prbc['by_expiry'][0]['expires_today']);

        $platelets = $this->cell($report, 'rh_positive_other', 'O+', 'Platelet Concentrate');
        $this->assertTrue($platelets['by_expiry'][0]['expires_tomorrow']);

        $this->assertSame(['today' => 1, 'tomorrow' => 1], $report['near_expiry']);
    }

    // --- How it is laid out --------------------------------------------------

    public function test_the_sheets_three_tables_and_the_extras_are_present(): void
    {
        $report = $this->report()->assertOk()->json();

        $this->assertSame(
            ['rh_positive_prbc', 'rh_negative', 'rh_positive_other', 'extras'],
            array_column($report['tables'], 'key')
        );

        $names = fn (string $key): array => array_column($this->table($report, $key)['columns'], 'name');

        $this->assertSame(['Packed RBC'], $names('rh_positive_prbc'));
        $this->assertSame(
            ['Packed RBC', 'Fresh Frozen Plasma', 'Cryoprecipitate', 'Platelet Concentrate', 'Cryosupernate'],
            $names('rh_negative')
        );
        $this->assertSame(
            ['Platelet Concentrate', 'Fresh Frozen Plasma', 'Cryoprecipitate', 'Cryosupernate'],
            $names('rh_positive_other')
        );
        $this->assertSame(['Washed RBC'], $names('extras'));
    }

    public function test_rows_follow_the_sheets_abo_order_within_each_rh(): void
    {
        $report = $this->report()->assertOk()->json();

        $types = fn (string $key): array => array_column($this->table($report, $key)['rows'], 'blood_type');

        $this->assertSame(['A+', 'B+', 'O+', 'AB+'], $types('rh_positive_prbc'));
        $this->assertSame(['A-', 'B-', 'O-', 'AB-'], $types('rh_negative'));
        $this->assertSame(['A+', 'B+', 'O+', 'AB+', 'A-', 'B-', 'O-', 'AB-'], $types('extras'));
    }

    public function test_rh_negative_stock_is_counted_in_its_own_table(): void
    {
        $this->unit('Packed RBC', 'A-', 16);
        $this->unit('Fresh Frozen Plasma', 'A-', 300);
        $this->unit('Fresh Frozen Plasma', 'A-', 300);

        $report = $this->report()->assertOk()->json();

        $this->assertSame(1, $this->cell($report, 'rh_negative', 'A-', 'Packed RBC')['total']);
        $this->assertSame(2, $this->cell($report, 'rh_negative', 'A-', 'Fresh Frozen Plasma')['total']);
        $this->assertSame(0, $this->table($report, 'rh_positive_prbc')['columns'][0]['total']);
    }

    public function test_column_totals_add_up(): void
    {
        $this->unit('Fresh Frozen Plasma', 'A+', 300);
        $this->unit('Fresh Frozen Plasma', 'B+', 300);
        $this->unit('Fresh Frozen Plasma', 'O+', 300);

        $table = $this->table($this->report()->assertOk()->json(), 'rh_positive_other');
        $ffp = collect($table['columns'])->firstWhere('name', 'Fresh Frozen Plasma');

        $this->assertSame(3, $ffp['total']);
        $this->assertSame(3, $table['total']);
    }

    // --- Dated columns follow the shelf life ---------------------------------

    public function test_short_shelf_life_components_are_dated_and_frozen_ones_are_totals(): void
    {
        $this->unit('Platelet Concentrate', 'A+', 2);
        $this->unit('Fresh Frozen Plasma', 'A+', 300);

        $report = $this->report()->assertOk()->json();
        $columns = collect($this->table($report, 'rh_positive_other')['columns'])->keyBy('name');

        $this->assertTrue($columns['Platelet Concentrate']['dated']);
        $this->assertFalse($columns['Fresh Frozen Plasma']['dated']);

        // A total only: no per-date breakdown for a frozen product.
        $this->assertSame([], $this->cell($report, 'rh_positive_other', 'A+', 'Fresh Frozen Plasma')['by_expiry']);
        $this->assertSame(1, $this->cell($report, 'rh_positive_other', 'A+', 'Fresh Frozen Plasma')['total']);

        $this->assertSame(42, $report['reference']['shelf_life_days']);
        $this->assertFalse($report['reference']['is_fallback']);
    }

    public function test_the_threshold_is_this_facilitys_packed_rbc_shelf_life(): void
    {
        // A centre that keeps its red cells 35 days: a 40-day product is
        // longer than its red cells, so it is reported as a total.
        FacilityBloodComponent::where('component_id', $this->components['Packed RBC']->id)
            ->update(['shelf_life_days' => 35]);
        FacilityBloodComponent::where('component_id', $this->components['Cryosupernate']->id)
            ->update(['shelf_life_days' => 40]);

        $report = $this->report()->assertOk()->json();
        $columns = collect($this->table($report, 'rh_positive_other')['columns'])->keyBy('name');

        $this->assertSame(35, $report['reference']['shelf_life_days']);
        $this->assertFalse($columns['Cryosupernate']['dated']);
    }

    public function test_the_threshold_falls_back_when_packed_rbc_is_not_configured(): void
    {
        FacilityBloodComponent::where('component_id', $this->components['Packed RBC']->id)->delete();

        $report = $this->report()->assertOk()->json();
        $columns = collect($this->table($report, 'rh_positive_other')['columns'])->keyBy('name');

        $this->assertTrue($report['reference']['is_fallback']);
        $this->assertSame(config('blood_center.stock_report.dated_fallback_days'), $report['reference']['shelf_life_days']);
        $this->assertTrue($columns['Platelet Concentrate']['dated']);

        // Packed RBC itself is now unconfigured, so it is a flagged total.
        $prbc = $this->table($report, 'rh_positive_prbc')['columns'][0];
        $this->assertFalse($prbc['dated']);
        $this->assertFalse($prbc['shelf_life_configured']);
    }

    public function test_a_component_with_no_shelf_life_is_flagged_and_still_counted(): void
    {
        $this->unit('Washed RBC', 'O+', 1);

        $report = $this->report()->assertOk()->json();
        $column = $this->table($report, 'extras')['columns'][0];

        $this->assertSame('Washed RBC', $column['name']);
        $this->assertFalse($column['shelf_life_configured']);
        $this->assertFalse($column['dated']);
        $this->assertSame(1, $column['total']);
        $this->assertSame(['Washed RBC'], $report['unconfigured']);
    }

    public function test_other_components_are_listed_even_with_no_stock(): void
    {
        $column = $this->table($this->report()->assertOk()->json(), 'extras')['columns'][0];

        $this->assertSame('Washed RBC', $column['name']);
        $this->assertSame(0, $column['total']);
    }

    public function test_date_slots_line_up_to_the_busiest_row(): void
    {
        $this->unit('Packed RBC', 'A+', 10);
        $this->unit('Packed RBC', 'A+', 11);
        $this->unit('Packed RBC', 'A+', 12);
        $this->unit('Packed RBC', 'B+', 10);

        $column = $this->table($this->report()->assertOk()->json(), 'rh_positive_prbc')['columns'][0];

        $this->assertSame(3, $column['date_slots']);
    }

    // --- Header and signature ------------------------------------------------

    public function test_it_carries_the_header_the_facility_and_who_prepared_it(): void
    {
        $this->report()
            ->assertOk()
            ->assertJsonPath('header', config('blood_center.stock_report.header'))
            ->assertJsonPath('facility.name', 'Sub-National Blood Center - Mindanao')
            ->assertJsonPath('facility.logo_url', null)
            ->assertJsonPath('prepared_by', 'JOHN ABARSOLO, RMT');
    }

    // --- Who may see it ------------------------------------------------------

    public function test_only_issuance_and_supervisors_may_prepare_it(): void
    {
        foreach ([Department::Collection, Department::Testing, Department::Processing, Department::Billing] as $department) {
            $staff = User::factory()->bloodCenterStaff($this->facility, $department)->create();

            $this->report($staff)->assertForbidden();
            $this->actingAs($staff)->get('/api/blood-center/inventory/stock-report/pdf')->assertForbidden();
        }

        $supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();
        $this->report($supervisor)->assertOk();
    }

    public function test_another_centres_report_is_its_own(): void
    {
        $this->unit('Packed RBC', 'A+', 10);

        $elsewhere = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), Department::Issuance)->create();

        $cell = $this->cell($this->report($elsewhere)->assertOk()->json(), 'rh_positive_prbc', 'A+', 'Packed RBC');

        $this->assertSame(0, $cell['total']);
    }

    // --- The PDF -------------------------------------------------------------

    public function test_the_pdf_downloads_as_the_printable_sheet(): void
    {
        $this->unit('Packed RBC', 'A+', 0);
        $this->unit('Platelet Concentrate', 'B+', 1);
        $this->unit('Fresh Frozen Plasma', 'O-', 300);
        $this->unit('Washed RBC', 'AB+', 1);

        $response = $this->actingAs($this->issuance)
            ->get('/api/blood-center/inventory/stock-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString('stock-inventory-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->issuance->id,
            'action' => 'inventory.stock_report_downloaded',
        ]);
    }

    public function test_the_pdf_renders_with_no_stock_at_all(): void
    {
        $this->actingAs($this->issuance)
            ->get('/api/blood-center/inventory/stock-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
