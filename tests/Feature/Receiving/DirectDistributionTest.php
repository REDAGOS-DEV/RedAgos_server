<?php

namespace Tests\Feature\Receiving;

use App\Enums\BloodUnitStatus;
use App\Enums\HospitalUnitStatus;
use App\Models\BloodUnit;
use App\Models\DirectDistribution;
use App\Models\Donation;
use App\Models\ExternalBloodSource;
use App\Models\Facility;
use App\Models\HospitalUnit;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * Bags from outside RedAgos, received for a Patient Transfusion Request.
 *
 * A Red Cross bag keeps the number its sender printed on it; RedAgos issues no
 * barcode of its own. A bag is identified by its source plus that number. It
 * becomes a blood unit keyed internally and tracing to the receipt instead of a
 * donation, booked under the hospital and already issued — so no centre ever
 * counts it — and enters custody as available stock, where everything the
 * hospital does with a bag works on it unchanged.
 */
class DirectDistributionTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    private TransfusionRequest $requirement;

    private ExternalBloodSource $redCross;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->requirement = $this->scenarioTransfusion();
        $this->redCross = ExternalBloodSource::query()->where('code', 'PRC')->firstOrFail();
    }

    public function test_philippine_red_cross_is_a_source_from_the_start(): void
    {
        $this->actingAs($this->requester)
            ->getJson('/api/hospital/external-blood-sources')
            ->assertOk()
            ->assertJsonPath('sources.0.name', 'Philippine Red Cross')
            ->assertJsonPath('sources.0.code', 'PRC');
    }

    public function test_receiving_a_bag_puts_it_on_the_shelf_under_the_senders_own_number(): void
    {
        $this->receive(' prc-920923323 ', ['collection_date' => OperationalDay::today()->subDays(2)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('direct_distribution.external_unit_number', 'PRC-920923323')
            ->assertJsonPath('direct_distribution.source.name', 'Philippine Red Cross')
            ->assertJsonPath('direct_distribution.transfusion_request.id', $this->requirement->id)
            ->assertJsonPath('direct_distribution.units.0.status', HospitalUnitStatus::Available->value)
            ->assertJsonPath('direct_distribution.units.0.bag_number', 'PRC-920923323')
            ->assertJsonPath('direct_distribution.received_by', trim($this->requester->first_name.' '.$this->requester->last_name));

        $delivery = DirectDistribution::query()->sole();
        $this->assertSame($this->hospital->id, $delivery->facility_id);
        $this->assertSame($this->requester->id, $delivery->received_by);

        // The internal key is never the number staff see, and RedAgos made no barcode of its own.
        $bag = BloodUnit::query()->findOrFail("DD-{$delivery->id}");
        $this->assertSame($this->hospital->id, $bag->facility_id);
        $this->assertNull($bag->donation_id);
        $this->assertSame($delivery->id, $bag->direct_distribution_id);
        $this->assertSame(BloodUnitStatus::Issued, $bag->status);
        $this->assertTrue($bag->isDirectDistribution());

        $custody = HospitalUnit::query()->where('unit_id', $bag->id)->sole();
        $this->assertSame(HospitalUnitStatus::Available, $custody->status);
        $this->assertNull($custody->request_allocation_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'direct_distribution.received', 'actor_id' => $this->requester->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hospital_inventory.stocked']);
    }

    public function test_inventory_shows_the_external_number_and_links_the_bag_to_the_patient(): void
    {
        $this->receive('PRC-100')->assertCreated();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bag_number', 'PRC-100')
            ->assertJsonPath('data.0.external_unit_number', 'PRC-100')
            ->assertJsonPath('data.0.blood_type.code', 'O+')
            ->assertJsonPath('data.0.source.type', 'direct_distribution')
            ->assertJsonPath('data.0.source.request_id', null)
            ->assertJsonPath('data.0.source.transfusion_request_id', $this->requirement->id)
            ->assertJsonPath('data.0.source.transfusion_reference', $this->requirement->reference_number)
            ->assertJsonPath('data.0.source.direct_distribution.source_name', 'Philippine Red Cross');

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/inventory?transfusion_request_id={$this->requirement->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?search=PRC-100')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_receiving_does_not_change_the_requirements_figures(): void
    {
        $before = $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$this->requirement->id}")
            ->assertOk()
            ->json('request.totals');

        $this->receive('PRC-200')->assertCreated();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$this->requirement->id}")
            ->assertOk()
            ->assertJsonPath('request.totals', $before);
    }

    public function test_a_received_bag_is_tagged_crossmatched_and_transfused_like_any_other(): void
    {
        $this->receive('PRC-300')->assertCreated();
        $unit = HospitalUnit::query()->sole();

        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();
        $this->act($unit, 'transfuse')->assertOk();

        $this->assertSame(HospitalUnitStatus::Transfused, $unit->fresh()->status);

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory/tag-events?search=PRC-300')
            ->assertOk()
            ->assertJsonPath('data.0.unit.bag_number', 'PRC-300');
    }

    public function test_the_hospitals_expiry_sweep_expires_a_received_bag_and_no_centre_counts_it(): void
    {
        $this->receive('PRC-400')->assertCreated();
        $unitId = BloodUnit::query()->sole()->id;

        DB::table('blood_units')->where('id', $unitId)
            ->update(['expiry_date' => OperationalDay::today()->subDay()->toDateString()]);

        $this->artisan('inventory:expire-units')->assertSuccessful();
        $this->assertSame(BloodUnitStatus::Issued, BloodUnit::query()->findOrFail($unitId)->status);

        $this->artisan('hospital:expire-units')->assertSuccessful();
        $this->assertSame(HospitalUnitStatus::Expired, HospitalUnit::query()->where('unit_id', $unitId)->sole()->status);
        $this->assertSame(BloodUnitStatus::Issued, BloodUnit::query()->findOrFail($unitId)->status, 'The hospital sweep never writes blood_units.');

        $this->actingAs($this->issuance)->getJson('/api/blood-center/inventory')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_same_bag_from_the_same_source_is_received_once(): void
    {
        $this->receive('PRC-500')->assertCreated();

        $this->receive('prc-500')
            ->assertStatus(409)
            ->assertJsonPath('code', 'external_unit_already_received');

        $this->assertDatabaseCount('direct_distributions', 1);
        $this->assertDatabaseCount('hospital_units', 1);
    }

    public function test_the_same_number_from_another_source_is_a_different_bag(): void
    {
        $other = ExternalBloodSource::factory()->create(['name' => 'Davao Regional Blood Service']);

        $this->receive('UNIT-77')->assertCreated();
        $this->receive('UNIT-77', ['external_blood_source_id' => $other->id])->assertCreated();

        $this->assertDatabaseCount('direct_distributions', 2);
    }

    public function test_the_unique_index_holds_the_source_and_number(): void
    {
        $columns = collect(Schema::getIndexes('direct_distributions'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
            ->pluck('columns')
            ->all();

        $this->assertContains(['external_blood_source_id', 'external_unit_number'], $columns);
    }

    public function test_a_redagos_bag_cannot_be_booked_as_an_external_one(): void
    {
        $donation = Donation::factory()->create(['facility_id' => $this->centre->id, 'donor_id' => $this->donorProfile->donor_id]);
        BloodUnit::factory()->create([
            'id' => '1234567-PRBC',
            'facility_id' => $this->centre->id,
            'donation_id' => $donation->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->prbc->id,
        ]);

        $this->receive('1234567-PRBC')
            ->assertStatus(409)
            ->assertJsonPath('code', 'redagos_unit');

        $this->assertDatabaseCount('direct_distributions', 0);
    }

    public function test_it_is_received_for_this_hospitals_open_requirement_only(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $stranger = User::factory()->bloodBankStaff($otherHospital)->create();

        $this->actingAs($stranger)
            ->postJson('/api/hospital/direct-distributions', $this->payload('PRC-600'))
            ->assertNotFound()
            ->assertJsonPath('code', 'transfusion_request_not_found');

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/transfusion-requests/{$this->requirement->id}/cancel")
            ->assertOk();

        $this->receive('PRC-601')
            ->assertStatus(409)
            ->assertJsonPath('code', 'transfusion_request_closed');

        $this->assertDatabaseCount('direct_distributions', 0);
    }

    public function test_the_bag_must_be_in_date_and_collected_before_it_expires(): void
    {
        $this->receive('PRC-700', ['expiry_date' => OperationalDay::today()->subDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expiry_date']);

        $this->receive('PRC-701', ['expiry_date' => OperationalDay::todayAsDate()])->assertCreated();

        $this->receive('PRC-702', [
            'collection_date' => OperationalDay::today()->addDays(30)->toDateString(),
            'expiry_date' => OperationalDay::today()->addDays(20)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['collection_date']);

        $this->receive('PRC-703', [
            'collection_date' => OperationalDay::today()->subDays(3)->toDateString(),
            'expiry_date' => OperationalDay::todayAsDate(),
        ])->assertCreated();
    }

    public function test_every_field_the_bag_needs_is_required_and_a_bad_number_is_refused(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/direct-distributions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'transfusion_request_id', 'external_blood_source_id', 'external_unit_number',
                'blood_type_id', 'component_id', 'expiry_date',
            ]);

        $this->receive('PRC_6/1')->assertStatus(422)->assertJsonValidationErrors(['external_unit_number']);
        $this->receive(str_repeat('A', 51))->assertStatus(422)->assertJsonValidationErrors(['external_unit_number']);
    }

    public function test_receipts_are_listed_for_the_patient_and_only_to_the_hospital_that_made_them(): void
    {
        $this->receive('PRC-800')->assertCreated();
        $this->receive('PRC-801')->assertCreated();

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $stranger = User::factory()->bloodBankStaff($otherHospital)->create();

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/direct-distributions?transfusion_request_id={$this->requirement->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/direct-distributions?search=PRC-801')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_unit_number', 'PRC-801');

        $this->actingAs($stranger)->getJson('/api/hospital/direct-distributions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->getJson('/api/hospital/inventory/'.BloodUnit::query()->orderBy('id')->value('id'))->assertNotFound();
    }

    public function test_a_blood_bank_adds_a_source_once_ignoring_case(): void
    {
        $this->actingAs($this->requester)
            ->postJson('/api/hospital/external-blood-sources', ['name' => '  Mindanao Blood Service ', 'code' => 'mbs'])
            ->assertCreated()
            ->assertJsonPath('source.name', 'Mindanao Blood Service')
            ->assertJsonPath('source.code', 'MBS');

        $this->assertDatabaseHas('audit_logs', ['action' => 'external_blood_source.created']);

        foreach (['mindanao blood service', 'PHILIPPINE RED CROSS'] as $name) {
            $this->actingAs($this->requester)
                ->postJson('/api/hospital/external-blood-sources', ['name' => $name])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['name']);
        }

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/external-blood-sources', ['name' => 'Another Service', 'code' => 'prc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_a_blood_centre_account_cannot_receive_or_add_sources(): void
    {
        $this->actingAs($this->issuance)->postJson('/api/hospital/direct-distributions', $this->payload('PRC-900'))->assertForbidden();
        $this->actingAs($this->issuance)->postJson('/api/hospital/external-blood-sources', ['name' => 'X'])->assertForbidden();
    }

    public function test_a_typed_batch_without_a_request_books_one_bag_per_unit(): void
    {
        $this->receiveBatch('PRC-920923323', 3)
            ->assertCreated()
            ->assertJsonPath('direct_distribution.quantity', 3)
            ->assertJsonPath('direct_distribution.transfusion_request', null)
            ->assertJsonPath('direct_distribution.requested_for', 'Ward 3 — Dr. Reyes')
            ->assertJsonCount(3, 'direct_distribution.units')
            ->assertJsonPath('direct_distribution.units.0.bag_number', 'PRC-920923323 #1')
            ->assertJsonPath('direct_distribution.units.2.bag_number', 'PRC-920923323 #3');

        $delivery = DirectDistribution::query()->sole();
        $this->assertNull($delivery->transfusion_request_id);
        $this->assertSame(3, $delivery->quantity);

        // One identifier, kept as given; the bags are keyed internally, as a second bag of a component is.
        $this->assertEqualsCanonicalizing(
            ["DD-{$delivery->id}", "DD-{$delivery->id}-2", "DD-{$delivery->id}-3"],
            BloodUnit::query()->pluck('id')->all()
        );
        $this->assertSame(3, HospitalUnit::query()->where('status', HospitalUnitStatus::Available)->count());

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/inventory?search=PRC-920923323')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.source.direct_distribution.requested_for', 'Ward 3 — Dr. Reyes')
            ->assertJsonPath('data.0.source.transfusion_request_id', null);

        // Who it was requested for can be a patient's name, so it stays out of the audit log.
        $this->assertStringNotContainsString(
            'Reyes',
            (string) DB::table('audit_logs')->where('action', 'direct_distribution.received')->value('context')
        );
    }

    public function test_a_bag_of_a_batch_is_tagged_like_any_other(): void
    {
        $this->receiveBatch('PRC-1200', 2)->assertCreated();
        $unit = HospitalUnit::query()->orderBy('id')->firstOrFail();

        $this->tagUnit($unit)->assertOk()->assertJsonPath('unit.bag_number', 'PRC-1200 #1');
    }

    public function test_a_single_typed_unit_keeps_its_identifier_without_a_count(): void
    {
        $this->receiveBatch('PRC-1300', 1)
            ->assertCreated()
            ->assertJsonPath('direct_distribution.units.0.bag_number', 'PRC-1300');
    }

    public function test_a_batch_needs_who_requested_it_and_a_sensible_count(): void
    {
        $payload = $this->batchPayload('PRC-1400', 2);
        unset($payload['requested_for']);

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/direct-distributions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requested_for', 'transfusion_request_id']);

        foreach ([0, 101] as $quantity) {
            $this->receiveBatch('PRC-1401', $quantity)->assertStatus(422)->assertJsonValidationErrors(['quantity']);
        }

        $this->assertDatabaseCount('direct_distributions', 0);
    }

    public function test_a_bag_for_a_patient_request_is_received_one_at_a_time(): void
    {
        $this->receive('PRC-1500', ['quantity' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_the_same_identifier_from_the_same_source_is_received_once_whichever_way(): void
    {
        $this->receive('PRC-1600')->assertCreated();

        $this->receiveBatch('prc-1600', 2)
            ->assertStatus(409)
            ->assertJsonPath('code', 'external_unit_already_received');

        $this->assertSame(1, BloodUnit::query()->count());
    }

    public function test_receipts_without_a_request_are_listed_and_searched_by_who_asked(): void
    {
        $this->receiveBatch('PRC-1700', 2)->assertCreated();

        $this->actingAs($this->requester)
            ->getJson('/api/hospital/direct-distributions?search=Reyes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 2);
    }

    public function test_a_blood_unit_traces_to_exactly_one_origin(): void
    {
        $this->receive('PRC-950')->assertCreated();
        $delivery = DirectDistribution::query()->sole();
        $donation = Donation::factory()->create(['facility_id' => $this->centre->id, 'donor_id' => $this->donorProfile->donor_id]);

        foreach ([['donation_id' => null], ['donation_id' => $donation->id, 'direct_distribution_id' => $delivery->id]] as $origin) {
            try {
                BloodUnit::factory()->create([
                    'facility_id' => $this->centre->id,
                    'blood_type_id' => $this->bloodType->id,
                    'component_id' => $this->prbc->id,
                    ...$origin,
                ]);

                $this->fail('A blood unit with no origin, or two, was saved.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('exactly one origin', $exception->getMessage());
            }
        }
    }

    /**
     * Receive one external bag for the scenario's requirement as the hospital's staff.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function receive(string $number, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->requester)
            ->postJson('/api/hospital/direct-distributions', $this->payload($number, $overrides));
    }

    /**
     * Receive a typed batch with no Patient Transfusion Request, as the hospital's staff.
     */
    private function receiveBatch(string $number, int $quantity): TestResponse
    {
        return $this->actingAs($this->requester)
            ->postJson('/api/hospital/direct-distributions', $this->batchPayload($number, $quantity));
    }

    /**
     * A batch from the Philippine Red Cross requested by a ward, with no Patient Transfusion Request.
     *
     * @return array<string, mixed>
     */
    private function batchPayload(string $number, int $quantity): array
    {
        $payload = $this->payload($number, ['quantity' => $quantity, 'requested_for' => 'Ward 3 — Dr. Reyes']);
        unset($payload['transfusion_request_id']);

        return $payload;
    }

    /**
     * One bag from the Philippine Red Cross: O+ packed cells expiring in three weeks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $number, array $overrides = []): array
    {
        return [
            'transfusion_request_id' => $this->requirement->id,
            'external_blood_source_id' => $this->redCross->id,
            'external_unit_number' => $number,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->prbc->id,
            'volume_ml' => 450,
            'expiry_date' => OperationalDay::today()->addDays(21)->toDateString(),
            ...$overrides,
        ];
    }
}
