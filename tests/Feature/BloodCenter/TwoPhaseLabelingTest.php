<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodCollection;
use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationComponent;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Support\BagNumbers;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Two-phase labelling: bags numbered from the barcode sticker at Processing,
 * given their final label by Processing once Testing clears them (project
 * owner, 2026-10-22), and booked in under those numbers.
 */
class TwoPhaseLabelingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $officer;

    private Donation $donation;

    private BloodComponent $prbc;

    private BloodComponent $ffp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create(['name' => 'Sub-National Blood Center - Mindanao']);
        $this->officer = User::factory()->bloodCenterStaff($this->facility, StaffRole::InventoryControlOfficer)->create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
        ]);

        $this->prbc = BloodComponent::factory()->create(['name' => 'Packed RBC', 'code' => 'PRBC']);
        $this->ffp = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma', 'code' => 'FFP']);

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Completed,
        ]);

        $this->barcode('1234567');
    }

    private function barcode(?string $barcode): void
    {
        BloodCollection::where('donation_id', $this->donation->id)->update([
            'donation_barcode' => $barcode,
            'facility_id' => $this->facility->id,
        ]);
    }

    /**
     * Processing's breakdown: PRBC 250, FFP 220, PRBC 200.
     */
    private function declareBags(): void
    {
        foreach ([[$this->prbc, 250], [$this->ffp, 220], [$this->prbc, 200]] as [$component, $volume]) {
            DonationComponent::factory()->create([
                'donation_id' => $this->donation->id,
                'component_id' => $component->id,
                'quantity' => 1,
                'volume_ml' => $volume,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $units
     */
    private function bookIn(array $units, ?User $as = null): TestResponse
    {
        $expiry = OperationalDay::today()->addDays(30)->toDateString();

        return $this->actingAs($as ?? $this->officer)->postJson('/api/blood-center/inventory', [
            'donation_id' => $this->donation->id,
            'units' => array_map(fn (array $unit): array => ['expiry_date' => $expiry, 'storage_location' => 'Cold Storage A-1', ...$unit], $units),
        ]);
    }

    private function bookAllBags(): void
    {
        $this->declareBags();
        $this->handOver();

        $this->bookIn([
            ['component_id' => $this->prbc->id],
            ['component_id' => $this->ffp->id],
            ['component_id' => $this->prbc->id],
        ])->assertCreated();
    }

    private function clearBoth(): void
    {
        $tester = User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create([
            'first_name' => 'Ben',
            'last_name' => 'Cruz',
        ]);

        foreach ([ClearanceKind::Tti, ClearanceKind::Immunohematology] as $kind) {
            DonationClearance::create([
                'donation_id' => $this->donation->id,
                'kind' => $kind,
                'issued_by' => $tester->id,
                'issued_at' => now(),
                'source' => 'test',
            ]);
        }
    }

    /**
     * Cleared by Testing and labelled by Processing: the only state Stock
     * Intake books in (project owner, 2026-10-22).
     */
    private function handOver(): void
    {
        $this->clearBoth();
        $this->donation->update(['labelled_at' => now()]);
    }

    private ?User $technologist = null;

    /**
     * The component technologist who prints the final labels.
     */
    private function technologist(): User
    {
        return $this->technologist ??= User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create([
            'first_name' => 'Lea',
            'last_name' => 'Navarro',
        ]);
    }

    private function printLabels(): TestResponse
    {
        return $this->actingAs($this->technologist())
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/labels");
    }

    /**
     * A shelf life for both components here: what the label's expiry is counted from.
     */
    private function shelfLives(): void
    {
        foreach ([[$this->prbc, 42], [$this->ffp, 365]] as [$component, $days]) {
            FacilityBloodComponent::updateOrCreate(
                ['facility_id' => $this->facility->id, 'component_id' => $component->id],
                ['shelf_life_days' => $days]
            );
        }
    }

    /**
     * Still with Processing, broken down, cleared by Testing, with shelf lives set.
     */
    private function readyToLabel(): void
    {
        $this->donation->update(['status' => DonationStatus::Collected]);
        $this->declareBags();
        $this->shelfLives();
        $this->clearBoth();
    }

    // --- Bag numbers ---------------------------------------------------------

    public function test_bags_are_numbered_from_the_sticker_and_what_they_hold(): void
    {
        $this->declareBags();

        $this->assertSame(
            ['1234567-PRBC', '1234567-FFP', '1234567-PRBC-2'],
            array_values(BagNumbers::forRows($this->donation))
        );
    }

    public function test_processing_sees_each_bags_number_for_its_phase_one_label(): void
    {
        $this->donation->update(['status' => DonationStatus::Collected]);
        $this->declareBags();

        $technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();

        $this->actingAs($technologist)
            ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
            ->assertOk()
            ->assertJsonPath('donation_barcode', '1234567')
            ->assertJsonPath('components.0.bag_number', '1234567-PRBC')
            ->assertJsonPath('components.2.bag_number', '1234567-PRBC-2');
    }

    public function test_a_component_without_a_code_is_numbered_by_its_initials(): void
    {
        $wholeBlood = BloodComponent::factory()->create(['name' => 'Whole Blood', 'code' => null]);

        DonationComponent::factory()->create([
            'donation_id' => $this->donation->id,
            'component_id' => $wholeBlood->id,
            'quantity' => 1,
            'volume_ml' => 450,
        ]);

        $this->assertSame(['1234567-WB'], array_values(BagNumbers::forRows($this->donation)));
    }

    public function test_a_donation_without_a_barcode_has_no_bag_numbers(): void
    {
        $this->barcode(null);
        $this->declareBags();

        $this->assertSame([null, null, null], array_values(BagNumbers::forRows($this->donation)));
    }

    public function test_a_legacy_row_standing_for_several_bags_gets_no_number(): void
    {
        DonationComponent::factory()->create([
            'donation_id' => $this->donation->id,
            'component_id' => $this->prbc->id,
            'quantity' => 3,
            'volume_ml' => null,
        ]);

        $this->assertSame([null], array_values(BagNumbers::forRows($this->donation)));
    }

    public function test_a_corrected_sticker_renumbers_the_bags_before_intake(): void
    {
        $this->declareBags();
        $this->barcode('7654321');

        // Computed on read, so nothing stored can go stale.
        $this->assertSame('7654321-PRBC', array_values(BagNumbers::forRows($this->donation->fresh()))[0]);
    }

    // --- Booking in ----------------------------------------------------------

    public function test_each_booked_unit_takes_its_bags_number_and_volume(): void
    {
        $this->bookAllBags();

        $units = BloodUnit::where('donation_id', $this->donation->id)->orderBy('id')->get();

        $this->assertSame(['1234567-FFP', '1234567-PRBC', '1234567-PRBC-2'], $units->pluck('id')->all());
        $this->assertSame(220, $units->firstWhere('id', '1234567-FFP')->volume_ml);
        $this->assertSame(250, $units->firstWhere('id', '1234567-PRBC')->volume_ml);
        $this->assertSame(200, $units->firstWhere('id', '1234567-PRBC-2')->volume_ml);
    }

    public function test_a_typed_unit_number_is_refused_for_a_barcoded_donation(): void
    {
        $this->declareBags();
        $this->handOver();

        $this->bookIn([['component_id' => $this->prbc->id, 'unit_id' => 'MY-OWN-1']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('units.0.unit_id');

        $this->assertSame(0, BloodUnit::where('donation_id', $this->donation->id)->count());
    }

    public function test_a_bag_number_already_used_elsewhere_is_refused(): void
    {
        $this->declareBags();
        $this->handOver();

        // Another centre printing the same sticker series.
        BloodUnit::factory()->create(['id' => '1234567-PRBC']);

        $this->bookIn([['component_id' => $this->prbc->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'bag_number_taken');
    }

    public function test_the_intake_queue_finds_a_donation_by_its_sticker(): void
    {
        $this->declareBags();

        $other = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Completed,
        ]);
        DonationComponent::factory()->create(['donation_id' => $other->id, 'component_id' => $this->prbc->id]);

        $rows = $this->actingAs($this->officer)
            ->getJson('/api/blood-center/inventory/intake-queue?barcode='.urlencode(' 1234567 '))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->donation->id, $rows[0]['donation_id']);

        $packed = collect($rows[0]['components'])->firstWhere('component_id', $this->prbc->id);

        $this->assertSame(
            [['bag_number' => '1234567-PRBC', 'volume_ml' => 250], ['bag_number' => '1234567-PRBC-2', 'volume_ml' => 200]],
            $packed['outstanding_bags']
        );
    }

    // --- Processing's final label (project owner, 2026-10-22) --------------------

    public function test_processing_prints_the_final_labels_once_testing_has_cleared_the_donation(): void
    {
        $this->readyToLabel();

        $response = $this->printLabels()
            ->assertOk()
            ->assertJsonPath('donation_barcode', '1234567')
            ->assertJsonPath('facility', 'Sub-National Blood Center - Mindanao')
            ->assertJsonCount(3, 'units')
            // In the order Processing declared the bags, under their Phase 1 numbers.
            ->assertJsonPath('units.0.unit_id', '1234567-PRBC')
            ->assertJsonPath('units.1.unit_id', '1234567-FFP')
            ->assertJsonPath('units.2.unit_id', '1234567-PRBC-2')
            ->assertJsonPath('units.0.labelled_by', 'Lea Navarro')
            ->assertJsonPath('labelled_by', 'Lea Navarro')
            ->assertJsonPath('clearances.0.label', 'TTI cleared')
            ->assertJsonPath('clearances.0.issued_by', 'Ben Cruz')
            ->assertJsonPath('clearances.1.label', 'ABO/Rh cleared');

        $this->assertMatchesRegularExpression('/^TTI-\d{6}$/', $response->json('clearances.0.code'));
        $this->assertMatchesRegularExpression('/^IH-\d{6}$/', $response->json('clearances.1.code'));

        $donation = $this->donation->fresh();
        $this->assertNotNull($donation->labelled_at);
        $this->assertSame($this->technologist()->id, (int) $donation->labelled_by);
    }

    public function test_the_final_label_carries_the_verified_blood_type_and_expiry(): void
    {
        $this->readyToLabel();

        $label = collect($this->printLabels()->assertOk()->json('units'))->firstWhere('unit_id', '1234567-FFP');

        $this->assertSame($this->donation->donorProfile->bloodType->code, $label['blood_type']);
        $this->assertSame('Fresh Frozen Plasma', $label['component']);
        $this->assertSame(220, $label['volume_ml']);
        // The day it was drawn, printed beside the volume.
        $this->assertSame(OperationalDay::dateOf($this->donation->donation_date), $this->printLabels()->json('collected_on'));
        // The day it was drawn plus the component's shelf life here.
        $this->assertSame(
            CarbonImmutable::parse(OperationalDay::dateOf($this->donation->donation_date))->addDays(365)->toDateString(),
            $label['expiry_date']
        );
    }

    public function test_no_final_label_until_testing_has_cleared_the_donation(): void
    {
        $this->donation->update(['status' => DonationStatus::Collected]);
        $this->declareBags();
        $this->shelfLives();

        $this->printLabels()->assertStatus(409)->assertJsonPath('code', 'tti_not_cleared');

        DonationClearance::create([
            'donation_id' => $this->donation->id,
            'kind' => ClearanceKind::Tti,
            'issued_at' => now(),
            'source' => 'test',
        ]);

        $this->printLabels()->assertStatus(409)->assertJsonPath('code', 'immunohematology_not_cleared');

        $this->assertNull($this->donation->fresh()->labelled_at);
    }

    public function test_no_final_label_without_a_shelf_life_or_a_bag_number(): void
    {
        $this->readyToLabel();
        FacilityBloodComponent::where('component_id', $this->ffp->id)->update(['shelf_life_days' => null]);

        $this->printLabels()->assertStatus(409)->assertJsonPath('code', 'shelf_life_missing');

        $this->shelfLives();
        $this->barcode(null);

        $this->printLabels()->assertStatus(409)->assertJsonPath('code', 'barcode_missing');
    }

    public function test_a_rejected_donation_is_never_labelled(): void
    {
        $this->readyToLabel();
        $this->donation->update(['status' => DonationStatus::Rejected]);

        $this->printLabels()->assertStatus(409)->assertJsonPath('code', 'donation_rejected');
    }

    public function test_final_labels_can_be_reprinted_and_never_name_the_donor(): void
    {
        $this->readyToLabel();
        $this->printLabels()->assertOk();

        $response = $this->printLabels()->assertOk()->assertJsonCount(3, 'units');

        $donor = $this->donation->donorProfile->donor;
        $this->assertStringNotContainsString($donor->first_name, $response->getContent());
        $this->assertStringNotContainsString($donor->last_name, $response->getContent());

        $this->assertSame(2, AuditLog::where('action', 'laboratory.labels_printed')->where('actor_id', $this->technologist()->id)->count());
    }

    public function test_only_processing_prints_final_labels(): void
    {
        $this->readyToLabel();

        $this->actingAs($this->officer)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/labels")
            ->assertForbidden();

        // Issuance's own label route is gone.
        $this->actingAs($this->officer)
            ->getJson("/api/blood-center/inventory/donations/{$this->donation->id}/labels")
            ->assertNotFound();
    }

    // --- Booking in labelled bags -------------------------------------------------

    public function test_labelled_cleared_bags_are_booked_straight_into_stock(): void
    {
        $this->readyToLabel();
        $this->printLabels()->assertOk();
        $this->donation->update(['status' => DonationStatus::Completed]);

        $this->bookIn([
            ['component_id' => $this->prbc->id],
            ['component_id' => $this->ffp->id],
            ['component_id' => $this->prbc->id],
        ])->assertCreated()->assertJsonPath('message', '3 blood units booked into stock.');

        $unit = BloodUnit::findOrFail('1234567-PRBC');
        $this->assertSame(BloodUnitStatus::Available, $unit->status);
        $this->assertNotNull($unit->released_at);
        $this->assertSame($this->officer->id, (int) $unit->released_by);
    }

    public function test_the_intake_queue_offers_the_expiry_on_the_label(): void
    {
        $this->readyToLabel();
        $this->printLabels()->assertOk();
        $this->donation->update(['status' => DonationStatus::Completed]);

        $row = $this->actingAs($this->officer)
            ->getJson('/api/blood-center/inventory/intake-queue')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($row['into_stock']);
        $this->assertSame(
            CarbonImmutable::parse(OperationalDay::dateOf($this->donation->donation_date))->addDays(42)->toDateString(),
            collect($row['components'])->firstWhere('component_id', $this->prbc->id)['expiry_date']
        );
    }

    // --- Bags handed over before labelling moved ----------------------------------

    public function test_a_bag_handed_over_unlabelled_is_refused_until_processing_labels_it(): void
    {
        // Handed over before 2026-10-22, untested and unlabelled. There is no
        // quarantine to hold it in any more (project owner, 2026-10-22).
        $this->declareBags();
        $this->shelfLives();

        $this->bookIn([['component_id' => $this->prbc->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_cleared');

        $this->clearBoth();

        $this->bookIn([['component_id' => $this->prbc->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'labels_not_printed');

        $this->assertSame(0, BloodUnit::where('donation_id', $this->donation->id)->count());

        // Processing labels it from the Processed list; then it books straight in.
        $this->printLabels()->assertOk();

        $this->bookIn([['component_id' => $this->prbc->id]])->assertCreated();

        $this->assertSame(BloodUnitStatus::Available, BloodUnit::findOrFail('1234567-PRBC')->status);
    }

    public function test_issuance_has_no_quarantine_release_any_more(): void
    {
        $this->actingAs($this->officer)
            ->postJson("/api/blood-center/inventory/quarantine/{$this->donation->id}/release")
            ->assertNotFound();
    }

    public function test_the_barcode_rename_can_be_rolled_back(): void
    {
        $migration = require database_path('migrations/2026_09_30_000001_rename_segment_number_to_donation_barcode.php');

        $migration->down();
        $this->assertTrue(Schema::hasColumn('blood_collections', 'segment_number'));
        $this->assertSame('1234567', BloodCollection::query()->where('donation_id', $this->donation->id)->value('segment_number'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('blood_collections', 'donation_barcode'));
        $this->assertSame('1234567', BloodCollection::query()->where('donation_id', $this->donation->id)->value('donation_barcode'));
    }
}
