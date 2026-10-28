<?php

namespace Tests\Feature\HospitalInventory;

use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\HospitalInventoryReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * The hospital's Inventory Reports: its paper form, filled from its own
 * shelf, each one kept as the PDF it was printed as.
 */
class HospitalStockSheetTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    private const URL = '/api/hospital/inventory/reports';

    private BloodComponent $wholeBlood;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->buildScenario();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00')); // 10:00 Manila

        // The form lists Whole Blood and Packed RBC, found by their codes.
        $this->prbc->update(['code' => 'PRBC']);
        $this->ffp->update(['code' => 'FFP']);
        $this->wholeBlood = BloodComponent::factory()->create(['name' => 'Whole Blood', 'code' => 'WB', 'price' => 0]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function generate(array $settings = []): TestResponse
    {
        return $this->actingAs($this->requester)->postJson(self::URL, $settings);
    }

    public function test_bags_ready_to_transfuse_are_crossmatched_and_the_rest_uncrossmatched(): void
    {
        [$free, $waiting, $ready] = $this->receiveStock(3);
        $this->tagUnit($waiting)->assertOk();
        $this->tagUnit($ready)->assertOk();
        $this->act($ready, 'crossmatch')->assertOk();

        [$wbFree, $wbReady] = $this->receiveStock(2, $this->wholeBlood);
        $this->tagUnit($wbReady)->assertOk();
        $this->act($wbReady, 'crossmatch')->assertOk();

        // Not on the sheet at all: a bag not yet stocked in.
        $this->receiveUnstocked(1);

        $response = $this->generate(['by' => 'all'])
            ->assertCreated()
            // The form, then one page of the other components.
            ->assertJsonCount(2, 'sheet.sheets')
            ->assertJsonPath('sheet.sheets.0.kind', 'form')
            ->assertJsonPath('sheet.sheets.0.blood_type', null)
            ->assertJsonPath('sheet.sheets.0.pages', 2)
            ->assertJsonPath('sheet.prepared_by', $this->requester->signatureName())
            ->assertJsonPath('sheet.footer.form_code', 'SPMC-F-BTS-38')
            ->assertJsonPath('sheet.totals.crossmatched', 2)
            ->assertJsonPath('sheet.totals.uncrossmatched', 3)
            // Crossmatched Whole Blood is listed to transfuse as PRBC; the WB
            // column is left for writing in.
            ->assertJsonPath('sheet.sheets.0.crossmatched.0.label', 'TRANSFUSE AS PRBC')
            ->assertJsonPath('sheet.sheets.0.crossmatched.0.cells.0.bag_number', $wbReady->unit_id)
            ->assertJsonPath('sheet.sheets.0.crossmatched.1.label', 'WB')
            ->assertJsonPath('sheet.sheets.0.crossmatched.2.cells.0.bag_number', $ready->unit_id)
            ->assertJsonPath('sheet.sheets.0.uncrossmatched.0.label', 'WB')
            ->assertJsonPath('sheet.sheets.0.uncrossmatched.0.cells.0.bag_number', $wbFree->unit_id)
            ->assertJsonPath('sheet.sheets.0.uncrossmatched.0.cells.0.blood_type', 'O+');

        $this->assertSame(array_fill(0, 20, null), $response->json('sheet.sheets.0.crossmatched.1.cells'));
        $this->assertCount(20, $response->json('sheet.sheets.0.uncrossmatched.1.cells'));

        // The free bag and the one waiting for its crossmatch, the waiting one marked.
        $prbc = collect($response->json('sheet.sheets.0.uncrossmatched.1.cells'))->filter()->keyBy('bag_number');
        $this->assertEqualsCanonicalizing([$free->unit_id, $waiting->unit_id], $prbc->keys()->all());
        $this->assertFalse($prbc[$free->unit_id]['tagged']);
        $this->assertTrue($prbc[$waiting->unit_id]['tagged']);
    }

    public function test_every_other_component_follows_the_form_a_column_each(): void
    {
        [$plasma] = $this->receiveStock(1, $this->ffp);
        [$platelets] = $this->receiveStock(1, $this->platelets);
        $this->tagUnit($platelets)->assertOk();
        $this->act($platelets, 'crossmatch')->assertOk();

        $this->generate(['by' => 'all'])
            ->assertCreated()
            ->assertJsonPath('sheet.sheets.1.kind', 'other')
            ->assertJsonPath('sheet.sheets.1.page', 2)
            // In catalogue order, by code where there is one, else by name.
            ->assertJsonPath('sheet.sheets.1.uncrossmatched.0.label', 'FFP')
            ->assertJsonPath('sheet.sheets.1.uncrossmatched.0.cells.0.bag_number', $plasma->unit_id)
            ->assertJsonPath('sheet.sheets.1.uncrossmatched.1.label', 'Platelet Concentrate')
            ->assertJsonPath('sheet.sheets.1.uncrossmatched.1.cells.0', null)
            ->assertJsonPath('sheet.sheets.1.crossmatched.1.cells.0.bag_number', $platelets->unit_id)
            // The grid keeps four columns, the spare ones blank.
            ->assertJsonCount(4, 'sheet.sheets.1.uncrossmatched')
            ->assertJsonPath('sheet.sheets.1.uncrossmatched.2.label', '')
            ->assertJsonPath('sheet.totals.crossmatched', 1)
            ->assertJsonPath('sheet.totals.uncrossmatched', 1);
    }

    public function test_by_default_every_blood_type_gets_a_sheet_of_its_own(): void
    {
        BloodType::firstOrCreate(['code' => 'A+'], ['label' => 'A+']);
        $unit = $this->receiveOne();

        $all = collect($this->generate()
            ->assertCreated()
            ->assertJsonPath('sheet.by', 'type')
            ->json('sheet.sheets'));

        // Each type: its form, then its other components.
        $this->assertCount(BloodType::query()->count() * 2, $all);
        $sheets = $all->where('kind', 'form')->keyBy('blood_type');

        $this->assertCount(BloodType::query()->count(), $sheets);
        $this->assertSame($unit->unit_id, $sheets['O+']['uncrossmatched'][1]['cells'][0]['bag_number']);
        $this->assertSame([], array_filter($sheets['A+']['uncrossmatched'][1]['cells']));
    }

    public function test_a_column_past_twenty_bags_continues_on_another_page(): void
    {
        $bags = $this->receiveStock(21, $this->wholeBlood, expiresInDays: 10);

        $this->generate(['by' => 'all'])
            ->assertCreated()
            // Two pages of the form, then the other components.
            ->assertJsonCount(3, 'sheet.sheets')
            ->assertJsonPath('sheet.sheets.0.pages', 3)
            ->assertJsonPath('sheet.sheets.1.page', 2)
            ->assertJsonPath('sheet.sheets.1.kind', 'form')
            ->assertJsonPath('sheet.sheets.2.kind', 'other')
            ->assertJsonPath('sheet.sheets.1.uncrossmatched.0.cells.0.bag_number', $bags->sortBy('unit_id')->last()->unit_id)
            ->assertJsonPath('sheet.sheets.1.uncrossmatched.0.cells.1', null);
    }

    public function test_a_bag_past_its_date_is_left_off(): void
    {
        $this->receiveOne(expiresInDays: 2);
        $this->travel(3)->days();

        $this->generate(['by' => 'all'])
            ->assertCreated()
            ->assertJsonPath('sheet.totals.uncrossmatched', 0);
    }

    public function test_the_report_covers_only_the_components_chosen(): void
    {
        [$plasma] = $this->receiveStock(1, $this->ffp);
        $this->receiveStock(1);

        // Plasma alone: no form page (it has no Whole Blood or Packed RBC to
        // show), and one column for it.
        $this->generate(['by' => 'all', 'components' => [$this->ffp->id]])
            ->assertCreated()
            ->assertJsonCount(1, 'sheet.sheets')
            ->assertJsonPath('sheet.sheets.0.kind', 'other')
            ->assertJsonPath('sheet.sheets.0.uncrossmatched.0.label', 'FFP')
            ->assertJsonPath('sheet.sheets.0.uncrossmatched.0.cells.0.bag_number', $plasma->unit_id)
            ->assertJsonPath('sheet.sheets.0.uncrossmatched.1.label', '')
            ->assertJsonPath('sheet.components', ['FFP'])
            ->assertJsonPath('report.components', ['FFP'])
            ->assertJsonPath('sheet.totals.uncrossmatched', 1);

        // Packed RBC alone: the form, and no other components.
        $this->generate(['by' => 'all', 'components' => [$this->prbc->id]])
            ->assertCreated()
            ->assertJsonCount(1, 'sheet.sheets')
            ->assertJsonPath('sheet.sheets.0.kind', 'form')
            ->assertJsonPath('sheet.totals.uncrossmatched', 1);
    }

    public function test_the_report_covers_only_the_blood_types_chosen(): void
    {
        $aPositive = BloodType::firstOrCreate(['code' => 'A+'], ['label' => 'A+']);
        $this->receiveOne();

        $sheets = collect($this->generate(['by' => 'type', 'components' => [$this->prbc->id], 'blood_types' => [$aPositive->id]])
            ->assertCreated()
            ->assertJsonPath('sheet.blood_types', ['A+'])
            // The O+ bag is not A+.
            ->assertJsonPath('sheet.totals.uncrossmatched', 0)
            ->json('sheet.sheets'));

        $this->assertSame(['A+'], $sheets->pluck('blood_type')->unique()->values()->all());
    }

    public function test_an_empty_or_malformed_choice_is_refused(): void
    {
        $this->generate(['components' => [], 'blood_types' => ['O+'], 'by' => 'component'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['components', 'blood_types.0', 'by']);

        $this->assertSame(0, HospitalInventoryReport::query()->count());
    }

    public function test_each_report_is_kept_as_its_pdf_and_listed_newest_first(): void
    {
        $this->receiveOne();

        $first = $this->generate(['by' => 'all'])->assertCreated()->json('report');
        $this->travel(1)->hours();
        $second = $this->generate(['by' => 'type', 'components' => [$this->prbc->id]])->assertCreated()->json('report');

        $stored = HospitalInventoryReport::query()->findOrFail($first['id']);
        Storage::disk('local')->assertExists($stored->file_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($stored->file_path));

        $this->actingAs($this->requester)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second['id'])
            ->assertJsonPath('data.0.layout', 'type')
            ->assertJsonPath('data.0.components', ['PRBC'])
            ->assertJsonPath('data.0.generated_by', $this->requester->signatureName())
            ->assertJsonPath('data.1.id', $first['id'])
            ->assertJsonPath('data.1.totals.uncrossmatched', 1);

        $this->assertTrue(AuditLog::query()->where('action', 'hospital_inventory.report_generated')->exists());
    }

    public function test_a_kept_report_downloads_exactly_as_it_was_printed(): void
    {
        $this->receiveOne();
        $report = $this->generate(['by' => 'all'])->assertCreated()->json('report');
        $printed = Storage::disk('local')->get(HospitalInventoryReport::query()->findOrFail($report['id'])->file_path);

        // The shelf changes afterwards; the kept report does not.
        $this->receiveStock(2);

        $response = $this->actingAs($this->requester)
            ->get(self::URL."/{$report['id']}/pdf")
            ->assertOk();

        $this->assertSame($printed, $response->streamedContent());
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inventory-sheet-2026-10-02-1000.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertTrue(AuditLog::query()->where('action', 'hospital_inventory.report_downloaded')->exists());
    }

    public function test_another_hospital_sees_none_of_the_reports(): void
    {
        $this->receiveOne();
        $report = $this->generate(['by' => 'all'])->assertCreated()->json('report');

        $elsewhere = User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->create();

        $this->actingAs($elsewhere)->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($elsewhere)->get(self::URL."/{$report['id']}/pdf")->assertNotFound();
    }
}
