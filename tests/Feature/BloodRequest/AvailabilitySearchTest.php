<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodUnitStatus;
use App\Enums\RoleName;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * What a hospital blood bank sees when it looks for stock.
 *
 * The load-bearing test here is the one asserting that a search changes
 * nothing. Everything else in this file describes which units count; that one
 * describes why a requester may browse freely without starving the network.
 */
class AvailabilitySearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $requester;

    private Facility $hospital;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        // firstOrCreate rather than the factory: BloodTypeFactory draws from a
        // unique pool of eight codes, and the nested donor chain behind a unit
        // exhausts it as soon as a test seeds more than a handful of units.
        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_reports_available_stock_per_facility(): void
    {
        $centre = Facility::factory()->approved()->create(['name' => 'Davao Blood Center']);
        $this->stockAt($centre, 8);

        $response = $this->actingAs($this->requester)->getJson($this->searchUrl(5));

        $response->assertOk()
            ->assertJsonPath('facilities.0.facility.name', 'Davao Blood Center')
            ->assertJsonPath('facilities.0.available', 8)
            ->assertJsonPath('facilities.0.can_fulfil', 5)
            ->assertJsonPath('facilities.0.covers_request', true)
            ->assertJsonPath('totals.units_available', 8);
    }

    public function test_a_facility_holding_less_than_the_ask_is_still_offered(): void
    {
        $centre = Facility::factory()->approved()->create();
        $this->stockAt($centre, 2);

        $this->actingAs($this->requester)->getJson($this->searchUrl(5))
            ->assertOk()
            ->assertJsonPath('facilities.0.available', 2)
            ->assertJsonPath('facilities.0.can_fulfil', 2)
            ->assertJsonPath('facilities.0.covers_request', false);
    }

    public function test_facilities_are_ordered_by_depth_of_stock(): void
    {
        $shallow = Facility::factory()->approved()->create(['name' => 'Shallow Centre']);
        $deep = Facility::factory()->approved()->create(['name' => 'Deep Centre']);
        $this->stockAt($shallow, 2);
        $this->stockAt($deep, 9);

        $this->actingAs($this->requester)->getJson($this->searchUrl(5))
            ->assertOk()
            ->assertJsonPath('facilities.0.facility.name', 'Deep Centre')
            ->assertJsonPath('facilities.1.facility.name', 'Shallow Centre');
    }

    public function test_searching_reserves_nothing(): void
    {
        $centre = Facility::factory()->approved()->create();
        $this->stockAt($centre, 4);

        $before = BloodUnit::query()->pluck('status', 'id')->all();

        $this->actingAs($this->requester)->getJson($this->searchUrl(4))->assertOk();

        $this->assertSame(
            $before,
            BloodUnit::query()->pluck('status', 'id')->all(),
            'A search is advisory. Displaying a unit must never hold it.'
        );
        $this->assertDatabaseCount('request_allocations', 0);
        $this->assertSame(
            4,
            BloodUnit::query()->where('status', BloodUnitStatus::Available)->count()
        );
    }

    public function test_the_response_says_it_is_advisory(): void
    {
        $this->stockAt(Facility::factory()->approved()->create(), 1);

        $this->actingAs($this->requester)->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath('advisory', true);
    }

    public function test_reserved_issued_expired_and_discarded_units_are_not_available(): void
    {
        $centre = Facility::factory()->approved()->create();

        $this->stockAt($centre, 1);
        $this->stockAt($centre, 1, BloodUnitStatus::Reserved);
        $this->stockAt($centre, 1, BloodUnitStatus::Issued);
        $this->stockAt($centre, 1, BloodUnitStatus::Expired);
        $this->stockAt($centre, 1, BloodUnitStatus::Discarded);

        $this->actingAs($this->requester)->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath('facilities.0.available', 1);
    }

    public function test_a_unit_past_its_expiry_is_not_offered_even_if_the_sweep_has_not_run(): void
    {
        $centre = Facility::factory()->approved()->create();

        $this->stockAt($centre, 1);
        $this->unitAt($centre, ['expiry_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->requester)->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath(
                'facilities.0.available',
                1,
                'Stock that cannot legally leave the building must not be offered.'
            );
    }

    public function test_stock_of_another_type_or_component_is_not_counted(): void
    {
        $centre = Facility::factory()->approved()->create();
        $otherType = BloodType::firstOrCreate(['code' => 'A-'], ['label' => 'A-']);
        $otherComponent = BloodComponent::factory()->create(['name' => 'Platelets']);

        $this->stockAt($centre, 1);
        $this->unitAt($centre, ['blood_type_id' => $otherType->id]);
        $this->unitAt($centre, ['component_id' => $otherComponent->id]);

        $this->actingAs($this->requester)->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath('facilities.0.available', 1);
    }

    public function test_the_requesters_own_stock_is_not_offered_back_to_them(): void
    {
        $this->stockAt($this->hospital, 6);

        $this->actingAs($this->requester)->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath('facilities', [])
            ->assertJsonPath('totals.units_available', 0);
    }

    public function test_an_unapproved_facilitys_stock_is_not_offered(): void
    {
        $this->stockAt(Facility::factory()->pendingApproval()->create(), 5);
        $this->stockAt(Facility::factory()->rejected()->create(), 5);

        $this->actingAs($this->requester)->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath('facilities', []);
    }

    public function test_another_blood_banks_stock_is_not_offered(): void
    {
        $this->stockAt(Facility::factory()->bloodBank()->approved()->create(), 5);

        $this->actingAs($this->requester)->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath(
                'facilities',
                [],
                'Only blood centres hold the requests.* abilities, so only they can fulfil.'
            );
    }

    public function test_blood_type_and_component_are_both_required(): void
    {
        $this->actingAs($this->requester)->getJson('/api/hospital/availability')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blood_type_id', 'component_id']);
    }

    public function test_a_quantity_below_one_is_refused(): void
    {
        $this->actingAs($this->requester)->getJson($this->searchUrl(0))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_eligible_targets_lists_approved_centres_only(): void
    {
        $open = Facility::factory()->approved()->create(['name' => 'Open Centre']);
        Facility::factory()->pendingApproval()->create(['name' => 'Pending Centre']);
        Facility::factory()->bloodBank()->approved()->create(['name' => 'Another Hospital']);

        $response = $this->actingAs($this->requester)->getJson('/api/hospital/facilities');

        $response->assertOk()->assertJsonCount(1, 'facilities');
        $this->assertSame($open->id, $response->json('facilities.0.id'));
    }

    public function test_a_centre_with_no_matching_stock_is_still_an_eligible_target(): void
    {
        Facility::factory()->approved()->create(['name' => 'Empty Centre']);

        $this->actingAs($this->requester)->getJson('/api/hospital/facilities')
            ->assertOk()
            ->assertJsonCount(1, 'facilities');
    }

    public function test_a_blood_centre_account_cannot_use_the_requester_routes(): void
    {
        $centreStaff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($centreStaff)->getJson($this->searchUrl())->assertForbidden();
        $this->actingAs($centreStaff)->getJson('/api/hospital/facilities')->assertForbidden();
    }

    public function test_a_donor_cannot_use_the_requester_routes(): void
    {
        // withRole rather than the donor() state: this asserts what the role
        // middleware does, and donor() drags in a profile whose blood type
        // collides with the one seeded above.
        $donor = User::factory()->withRole(RoleName::Donor)->create();

        $this->actingAs($donor)->getJson($this->searchUrl())->assertForbidden();
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson($this->searchUrl())->assertUnauthorized();
        $this->getJson('/api/hospital/facilities')->assertUnauthorized();
    }

    public function test_staff_of_an_unapproved_blood_bank_are_refused(): void
    {
        $pending = Facility::factory()->bloodBank()->pendingApproval()->create();
        $staff = User::factory()->bloodBankStaff($pending)->create();

        $this->actingAs($staff)->getJson($this->searchUrl())
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_approved');
    }

    public function test_an_unverified_account_is_refused(): void
    {
        $staff = User::factory()->bloodBankStaff($this->hospital)->unverified()->create();

        $this->actingAs($staff)->getJson($this->searchUrl())
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    /**
     * Seed a number of matching units at a facility.
     *
     * The donation is created explicitly and shared across the units, because
     * leaving donation_id to the factory spawns a fresh donor and blood type
     * per unit and drains BloodTypeFactory's unique pool.
     */
    private function stockAt(Facility $facility, int $count, ?BloodUnitStatus $status = null): void
    {
        $donation = Donation::factory()->create([
            'facility_id' => $facility->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->count($count)->create([
            'facility_id' => $facility->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => $status ?? BloodUnitStatus::Available,
        ]);
    }

    /**
     * Seed one unit at a facility, overriding whatever the case needs.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function unitAt(Facility $facility, array $overrides = []): void
    {
        $donation = Donation::factory()->create([
            'facility_id' => $facility->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->create([
            'facility_id' => $facility->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
            ...$overrides,
        ]);
    }

    private function searchUrl(?int $quantity = null): string
    {
        $query = [
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
        ];

        if ($quantity !== null) {
            $query['quantity'] = $quantity;
        }

        return '/api/hospital/availability?'.http_build_query($query);
    }
}
