<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * A walk-in for a hospital that is not on RedAgos (project owner, 2026-10-19):
 * staff type its name, and it is kept as an unregistered hospital — a name and
 * nothing else — until an administrator creates it properly.
 */
class UnregisteredHospitalWalkInTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_a_walk_in_is_recorded_for_a_hospital_named_by_hand(): void
    {
        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => null,
                'hospital_name' => '  St. Jude   Hospital ',
            ]))
            ->assertCreated()
            ->assertJsonPath('request.requesting_facility.name', 'St. Jude Hospital')
            ->assertJsonPath('request.requesting_facility.is_registered', false);

        $hospital = Facility::query()->where('name', 'St. Jude Hospital')->sole();

        $this->assertSame(FacilityStatus::Unregistered, $hospital->status);
        $this->assertNull($hospital->address);
        $this->assertSame(0, User::query()->where('facility_id', $hospital->id)->count());

        // The patient's requirement is the hospital's, numbered in its sequence.
        $this->assertSame(
            $hospital->id,
            TransfusionRequest::query()->whereKey(BloodRequest::query()->findOrFail($response->json('request.id'))->transfusion_request_id)->value('facility_id')
        );
    }

    public function test_the_same_name_typed_again_is_the_same_hospital_and_finds_its_request(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => null,
                'hospital_name' => 'St. Jude Hospital',
            ]))
            ->assertCreated();

        // Another centre, the watcher carrying the rest: typed differently.
        $this->actingAs($this->otherIssuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', [
                'hospital_name' => 'st. jude hospital',
                'patient_surname' => 'Dela Cruz',
                'patient_first_name' => 'Juan',
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'matches');

        $this->assertSame(1, Facility::query()->where('status', FacilityStatus::Unregistered->value)->count());
    }

    public function test_an_unknown_name_has_nothing_to_find_and_creates_nothing_on_a_lookup(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', [
                'hospital_name' => 'Nowhere General',
                'patient_surname' => 'Dela Cruz',
                'patient_first_name' => 'Juan',
                'blood_type_id' => $this->bloodType->id,
            ])
            ->assertOk()
            ->assertJsonCount(0, 'matches');

        $this->assertSame(0, Facility::query()->where('status', FacilityStatus::Unregistered->value)->count());
    }

    public function test_a_registered_hospitals_name_typed_by_hand_is_that_hospital(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => null,
                'hospital_name' => 'southern hospital blood bank',
            ]))
            ->assertCreated()
            ->assertJsonPath('request.requesting_facility.id', $this->hospital->id)
            ->assertJsonPath('request.requesting_facility.is_registered', true);

        $this->assertSame(0, Facility::query()->where('status', FacilityStatus::Unregistered->value)->count());
    }

    public function test_a_walk_in_needs_a_hospital_chosen_or_named(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => null,
                'hospital_name' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hospital_id', 'hospital_name']);
    }

    public function test_the_form_lists_a_named_hospital_as_not_registered(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => null,
                'hospital_name' => 'St. Jude Hospital',
            ]))
            ->assertCreated();

        $hospitals = collect(
            $this->actingAs($this->otherIssuance)
                ->getJson('/api/blood-center/blood-requests/walk-in/reference')
                ->assertOk()
                ->json('hospitals')
        )->keyBy('name');

        $this->assertFalse($hospitals['St. Jude Hospital']['registered']);
        $this->assertTrue($hospitals['Southern Hospital Blood Bank']['registered']);
    }

    public function test_it_is_not_listed_with_the_facilities_an_administrator_manages(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => null,
                'hospital_name' => 'St. Jude Hospital',
            ]))
            ->assertCreated();

        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $names = array_column(
            $this->actingAs($admin)->getJson('/api/admin/facilities?per_page=100')->assertOk()->json('data'),
            'name'
        );

        $this->assertNotContains('St. Jude Hospital', $names);
        $this->assertContains('Southern Hospital Blood Bank', $names);
    }

    public function test_creating_the_hospital_later_upgrades_the_record_and_keeps_its_requests(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'hospital_id' => null,
                'hospital_name' => 'St. Jude Hospital',
            ]))
            ->assertCreated();

        $unregistered = Facility::query()->where('name', 'St. Jude Hospital')->sole();

        User::factory()->withRole(RoleName::Admin)->create();

        $this->artisan('facility:create', [
            '--type' => 'blood_bank',
            '--name' => 'ST. JUDE HOSPITAL',
            '--doh-license' => 'DOH-BB-55501',
            '--address' => 'Tagum City',
            '--email' => 'bloodbank@stjude.test',
            '--phone' => '09171230000',
            '--account-first-name' => 'Rosa',
            '--account-last-name' => 'Lim',
            '--account-position' => 'Blood Bank Head',
            '--account-email' => 'rosa@stjude.test',
            '--account-phone' => '09181230000',
            '--account-password' => 'Sup3rSecret',
        ])->assertSuccessful();

        $hospital = $unregistered->fresh();

        $this->assertSame(FacilityStatus::Approved, $hospital->status);
        $this->assertSame('ST. JUDE HOSPITAL', $hospital->name);
        $this->assertSame(1, Facility::query()->whereRaw('LOWER(name) = ?', ['st. jude hospital'])->count());
        $this->assertSame(1, TransfusionRequest::query()->where('facility_id', $hospital->id)->count());
    }
}
