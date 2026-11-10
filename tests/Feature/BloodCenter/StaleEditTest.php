<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\StaffRole;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\DeferralCode;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\StockThreshold;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * A settings save made against values someone else has changed since is refused.
 *
 * Each form sends, beside its changes, what it showed when it loaded. If the
 * stored value is no longer that, the save answers 409 `stale_edit` and writes
 * nothing, rather than quietly putting back the other person's change. A save
 * that says nothing about what it loaded is not checked, as before.
 */
class StaleEditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
    }

    // ---- Component prices -------------------------------------------------

    public function test_a_price_save_from_a_stale_list_is_refused_and_writes_nothing(): void
    {
        $supervisor = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingSupervisor)->create();
        $packed = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $plasma = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);
        $this->price($packed, 500);
        $this->price($plasma, 300);

        // Someone else raised plasma to 350 after this list was loaded at 300.
        $this->price($plasma, 350);

        $this->actingAs($supervisor)
            ->putJson('/api/blood-center/component-prices', ['prices' => [
                ['component_id' => $packed->id, 'no_charge' => false, 'price' => 600, 'loaded_price' => 500],
                ['component_id' => $plasma->id, 'no_charge' => false, 'price' => 300, 'loaded_price' => 300],
            ]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_edit')
            ->assertJsonPath('components', [$plasma->id])
            ->assertJsonPath('message', 'Someone else changed the price of Fresh Frozen Plasma after you opened this page. Check it and save again.');

        // Nothing written: not even the line that was current.
        $this->assertEquals(500, $this->storedPrice($packed));
        $this->assertEquals(350, $this->storedPrice($plasma));
    }

    public function test_a_price_save_from_the_current_list_goes_through(): void
    {
        $supervisor = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingSupervisor)->create();
        $packed = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $free = BloodComponent::factory()->create(['name' => 'Cryoprecipitate']);
        $this->price($free, 0);

        $this->actingAs($supervisor)
            ->putJson('/api/blood-center/component-prices', ['prices' => [
                // Never priced: loaded as null.
                ['component_id' => $packed->id, 'no_charge' => false, 'price' => 450, 'loaded_price' => null],
                // No charge: loaded as 0.
                ['component_id' => $free->id, 'no_charge' => true, 'loaded_price' => 0],
            ]])
            ->assertOk();

        $this->assertEquals(450, $this->storedPrice($packed));
    }

    public function test_a_price_save_that_says_nothing_about_what_it_loaded_is_not_checked(): void
    {
        $supervisor = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingSupervisor)->create();
        $packed = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->price($packed, 500);

        $this->actingAs($supervisor)
            ->putJson('/api/blood-center/component-prices', ['prices' => [
                ['component_id' => $packed->id, 'no_charge' => false, 'price' => 650],
            ]])
            ->assertOk();

        $this->assertEquals(650, $this->storedPrice($packed));
    }

    // ---- Stock thresholds -------------------------------------------------

    public function test_a_threshold_save_against_a_changed_level_is_refused(): void
    {
        $officer = User::factory()->bloodCenterStaff($this->facility)->create();
        [$type, $component] = $this->cell();
        $this->threshold($type, $component, minimum: 4, par: 10);

        $this->actingAs($officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [[
                'blood_type_id' => $type->id,
                'component_id' => $component->id,
                'minimum_units' => 6,
                'alerts_enabled' => true,
                // Loaded when the critical level was still 3.
                'expected' => ['minimum_units' => 3, 'alerts_enabled' => true],
            ]]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_edit')
            ->assertJsonPath('message', 'Someone else changed one of these levels after you opened this page. Check them and save again.');

        $this->assertSame(4, StockThreshold::query()->sole()->minimum_units);
    }

    public function test_a_threshold_save_against_the_current_levels_goes_through(): void
    {
        $officer = User::factory()->bloodCenterStaff($this->facility)->create();
        [$type, $component] = $this->cell();
        $this->threshold($type, $component, minimum: 4, par: 10);
        [$newType, $newComponent] = $this->cell('A+');

        $this->actingAs($officer)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => [
                [
                    'blood_type_id' => $type->id,
                    'component_id' => $component->id,
                    'par_units' => 12,
                    'expected' => ['par_units' => 10],
                ],
                [
                    // A cell with nothing saved yet: no levels, alerts on.
                    'blood_type_id' => $newType->id,
                    'component_id' => $newComponent->id,
                    'minimum_units' => 2,
                    'alerts_enabled' => true,
                    'expected' => ['minimum_units' => null, 'alerts_enabled' => true],
                ],
            ]])
            ->assertOk();

        $this->assertSame(12, StockThreshold::query()->where('blood_type_id', $type->id)->value('par_units'));
        $this->assertSame(2, StockThreshold::query()->where('blood_type_id', $newType->id)->value('minimum_units'));
    }

    // ---- Deferral codes ---------------------------------------------------

    public function test_a_deferral_code_save_against_a_changed_reason_is_refused(): void
    {
        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $code = $this->deferralCode($physician, 'R13', 'Recent tattoo');

        $code->forceFill(['reason' => 'Recent tattoo or piercing'])->save();

        $this->actingAs($physician)
            ->patchJson("/api/blood-center/deferral-codes/{$code->id}", [
                'reason' => 'Tattoo in the last year',
                'expected' => ['reason' => 'Recent tattoo'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_edit')
            ->assertJsonPath('message', 'Someone else changed R13 after you opened this page. Check it and save again.');

        $this->assertSame('Recent tattoo or piercing', $code->fresh()->reason);
    }

    public function test_retiring_a_code_someone_else_already_retired_is_refused(): void
    {
        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $code = $this->deferralCode($physician, 'R13', 'Recent tattoo');

        $code->forceFill(['retired_at' => now()])->save();

        $this->actingAs($physician)
            ->patchJson("/api/blood-center/deferral-codes/{$code->id}", [
                'retired' => false,
                'expected' => ['retired' => false],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_edit');

        $this->assertNotNull($code->fresh()->retired_at);
    }

    public function test_a_deferral_code_save_against_the_current_code_goes_through(): void
    {
        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $code = $this->deferralCode($physician, 'R13', 'Recent tattoo');

        $this->actingAs($physician)
            ->patchJson("/api/blood-center/deferral-codes/{$code->id}", [
                'reason' => 'Tattoo in the last year',
                'expected' => ['reason' => 'Recent tattoo'],
            ])
            ->assertOk();

        $this->assertSame('Tattoo in the last year', $code->fresh()->reason);
    }

    // ---- Staff ------------------------------------------------------------

    public function test_a_staff_save_against_a_changed_account_is_refused_and_writes_nothing(): void
    {
        $admin = User::factory()->bloodCenterAdmin($this->facility)->create();
        $staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create(['employee_id' => 'EMP-1']);
        $loaded = $this->actingAs($admin)->getJson("/api/blood-center/staff/{$staff->uuid}")->assertOk()->json();

        // Another Admin changes the employee ID after this dialog opened.
        $staff->forceFill(['employee_id' => 'EMP-2'])->save();

        $this->actingAs($admin)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'employee_id' => 'EMP-1',
                'credentials' => 'RMT',
                'expected' => [
                    'employee_id' => $loaded['employee_id'],
                    'credentials' => $loaded['credentials'],
                    'staff_privileges' => $loaded['staff_privileges'],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_edit')
            ->assertJsonPath('message', 'Someone else changed this account after you opened it. Check it and save again.');

        $staff->refresh();
        $this->assertSame('EMP-2', $staff->employee_id);
        $this->assertNull($staff->credentials);
    }

    public function test_a_staff_save_against_the_current_account_goes_through(): void
    {
        $admin = User::factory()->bloodCenterAdmin($this->facility)->create();
        $staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create(['employee_id' => 'EMP-1']);
        $loaded = $this->actingAs($admin)->getJson("/api/blood-center/staff/{$staff->uuid}")->assertOk()->json();

        $this->actingAs($admin)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'credentials' => 'RMT',
                'expected' => [
                    'credentials' => $loaded['credentials'],
                    'staff_role' => $loaded['staff_role'],
                    'additional_staff_roles' => $loaded['additional_staff_roles'],
                    // Same privileges in another order is not a change.
                    'staff_privileges' => array_reverse($loaded['staff_privileges']),
                ],
            ])
            ->assertOk();

        $this->assertSame('RMT', $staff->fresh()->credentials);
    }

    // ---- helpers ----------------------------------------------------------

    private function price(BloodComponent $component, int|float $price): void
    {
        FacilityBloodComponent::query()->updateOrCreate(
            ['facility_id' => $this->facility->id, 'component_id' => $component->id],
            ['price' => $price],
        );
    }

    private function storedPrice(BloodComponent $component): mixed
    {
        return FacilityBloodComponent::query()
            ->where('facility_id', $this->facility->id)
            ->where('component_id', $component->id)
            ->value('price');
    }

    /**
     * @return array{0: BloodType, 1: BloodComponent}
     */
    private function cell(string $type = 'O+'): array
    {
        return [
            BloodType::firstOrCreate(['code' => $type], ['label' => $type]),
            BloodComponent::factory()->create(),
        ];
    }

    private function threshold(BloodType $type, BloodComponent $component, int $minimum, int $par): void
    {
        StockThreshold::query()->create([
            'facility_id' => $this->facility->id,
            'blood_type_id' => $type->id,
            'component_id' => $component->id,
            'minimum_units' => $minimum,
            'par_units' => $par,
            'alerts_enabled' => true,
        ]);
    }

    private function deferralCode(User $physician, string $code, string $reason): DeferralCode
    {
        $id = $this->actingAs($physician)
            ->postJson('/api/blood-center/deferral-codes', ['code' => $code, 'reason' => $reason])
            ->assertCreated()
            ->json('data.id');

        return DeferralCode::query()->findOrFail($id);
    }
}
