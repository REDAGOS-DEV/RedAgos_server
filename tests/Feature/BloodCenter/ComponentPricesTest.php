<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * What a blood centre charges per unit of each component: the Billing
 * department's alone, and its head's (billing.prices, project owner
 * 2026-10-15).
 *
 * Held per facility for the same reason as the shelf life beside it: a
 * non-zero price switches on the payment-before-release gate, which one centre
 * must not be able to turn on for another.
 *
 * Saved all at once, and only when every component has a price or an explicit
 * no charge (project owner, 2026-10-15): a blank no longer means free.
 */
class ComponentPricesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = '/api/blood-center/component-prices';

    private User $billingSupervisor;

    private BloodComponent $component;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billingSupervisor = User::factory()->bloodCenterStaff(post: StaffRole::BillingSupervisor)->create();
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
    }

    public function test_the_billing_supervisor_sees_which_components_are_unpriced(): void
    {
        BloodComponent::factory()->create(['name' => 'Platelet Concentrate']);

        $this->actingAs($this->billingSupervisor)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.unpriced', 2)
            ->assertJsonPath('meta.facility.id', $this->billingSupervisor->facility_id)
            ->assertJsonPath('data.0.price', null)
            ->assertJsonPath('data.0.no_charge', false)
            ->assertJsonMissingPath('data.0.shelf_life_days');
    }

    public function test_every_price_is_saved_in_one_go_and_each_is_audited(): void
    {
        $plasma = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 1500],
                ['component_id' => $plasma->id, 'no_charge' => true, 'price' => null],
            ]])
            ->assertOk()
            ->assertJsonPath('message', "2 prices saved for {$this->billingSupervisor->facility->name}.")
            ->assertJsonPath('meta.unpriced', 0)
            ->assertJsonPath('data.0.name', 'Fresh Frozen Plasma')
            ->assertJsonPath('data.0.no_charge', true)
            ->assertJsonPath('data.0.price', 0)
            ->assertJsonPath('data.1.no_charge', false)
            ->assertJsonPath('data.1.price', 1500);

        $this->assertDatabaseHas('facility_blood_components', [
            'facility_id' => $this->billingSupervisor->facility_id,
            'component_id' => $this->component->id,
            'price' => '1500.00',
            'shelf_life_days' => null,
        ]);

        $audits = AuditLog::query()->where('action', 'billing.component_priced')->get()->keyBy('context.component_id');
        $this->assertCount(2, $audits);
        $this->assertSame($this->billingSupervisor->id, $audits[$plasma->id]->actor_id);
        $this->assertNull($audits[$plasma->id]->context['price_before']);
        $this->assertSame('0.00', $audits[$plasma->id]->context['price_after']);
        $this->assertTrue($audits[$plasma->id]->context['no_charge']);
        $this->assertFalse($audits[$this->component->id]->context['no_charge']);
    }

    public function test_nothing_is_saved_while_a_component_has_no_price(): void
    {
        $plasma = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 1500],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'prices_incomplete')
            ->assertJsonPath('message', 'Cannot save prices. Configure a price for: Fresh Frozen Plasma.')
            ->assertJsonPath('components', [$plasma->id]);

        $this->assertDatabaseCount('facility_blood_components', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_a_component_already_priced_may_be_left_out(): void
    {
        $plasma = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);

        FacilityBloodComponent::create([
            'facility_id' => $this->billingSupervisor->facility_id,
            'component_id' => $plasma->id,
            'price' => 900,
        ]);

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => true],
            ]])
            ->assertOk()
            ->assertJsonPath('meta.unpriced', 0);
    }

    /**
     * Free is a decision, so it is said with no_charge. A blank or a zero
     * price would leave it unclear whether anyone decided.
     */
    public function test_a_blank_or_zero_price_is_refused(): void
    {
        $cases = [
            'blank' => ['no_charge' => false, 'price' => null],
            'missing' => ['no_charge' => false],
            'zero' => ['no_charge' => false, 'price' => 0],
            'negative' => ['no_charge' => false, 'price' => -1],
            'three decimals' => ['no_charge' => false, 'price' => 10.555],
            'free and priced' => ['no_charge' => true, 'price' => 100],
        ];

        foreach ($cases as $line) {
            $this->actingAs($this->billingSupervisor)
                ->putJson(self::ENDPOINT, ['prices' => [['component_id' => $this->component->id, ...$line]]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('prices.0.price');
        }

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [['component_id' => $this->component->id, 'price' => 100]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prices.0.no_charge');

        $this->assertDatabaseCount('facility_blood_components', 0);
    }

    public function test_an_unchanged_price_is_neither_rewritten_nor_audited(): void
    {
        FacilityBloodComponent::create([
            'facility_id' => $this->billingSupervisor->facility_id,
            'component_id' => $this->component->id,
            'price' => 1500,
        ]);

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => '1500.00'],
            ]])
            ->assertOk()
            ->assertJsonPath('message', 'No prices changed.');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_a_price_save_leaves_the_shelf_life_alone(): void
    {
        FacilityBloodComponent::create([
            'facility_id' => $this->billingSupervisor->facility_id,
            'component_id' => $this->component->id,
            'shelf_life_days' => 42,
        ]);

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 750.5],
            ]])
            ->assertOk()
            ->assertJsonPath('data.0.price', 750.5);

        $setting = FacilityBloodComponent::query()->sole();
        $this->assertSame(42, $setting->shelf_life_days);
        $this->assertSame('750.50', $setting->price);
    }

    public function test_a_shelf_life_sent_here_is_refused(): void
    {
        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 100, 'shelf_life_days' => 42],
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prices.0.shelf_life_days');
    }

    public function test_an_unknown_retired_or_repeated_component_is_refused(): void
    {
        $retired = BloodComponent::factory()->create(['name' => 'Retired Component']);
        $retired->delete();

        foreach ([999999, $retired->id] as $componentId) {
            $this->actingAs($this->billingSupervisor)
                ->putJson(self::ENDPOINT, ['prices' => [
                    ['component_id' => $componentId, 'no_charge' => true],
                ]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('prices.0.component_id');
        }

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => true],
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 100],
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prices.0.component_id');
    }

    public function test_one_centres_prices_do_not_reach_another(): void
    {
        $elsewhere = User::factory()->bloodCenterStaff(post: StaffRole::BillingSupervisor)->create();

        $this->actingAs($this->billingSupervisor)
            ->putJson(self::ENDPOINT, ['prices' => [
                ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 1500],
            ]])
            ->assertOk();

        $row = collect($this->actingAs($elsewhere)->getJson(self::ENDPOINT)->json('data'))
            ->firstWhere('id', $this->component->id);

        $this->assertNull($row['price']);
        $this->assertFalse($row['no_charge']);
    }

    /**
     * Billing's head alone: not the clerk, not Processing, not a custom
     * Billing role, and neither management level.
     */
    public function test_only_the_billing_supervisor_prices_components(): void
    {
        $facility = $this->billingSupervisor->facility;

        $refused = [
            User::factory()->bloodCenterStaff($facility, StaffRole::BillingClerk)->create(),
            User::factory()->bloodCenterStaff($facility, StaffRole::ComponentTechnologist)->create(),
            User::factory()->bloodCenterCustomStaff($facility, Department::Billing)->create(),
            User::factory()->bloodCenterAdmin($facility)->create(),
            User::factory()->bloodCenterSupervisor($facility)->create(),
        ];

        foreach ($refused as $user) {
            $this->actingAs($user)->getJson(self::ENDPOINT)->assertForbidden();

            $this->actingAs($user)
                ->putJson(self::ENDPOINT, ['prices' => [
                    ['component_id' => $this->component->id, 'no_charge' => false, 'price' => 1],
                ]])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('facility_blood_components', 0);
    }
}
