<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\BillingProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BillingProgramsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = User::factory()->bloodCenterStaff(post: StaffRole::BillingSupervisor)->create();
    }

    public function test_the_billing_supervisor_can_schedule_a_government_subsidy_with_effective_dates(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/billing-programs', [
                'name' => 'DOH fourth-quarter subsidy',
                'kind' => 'government_subsidy',
                'effective_from' => now()->toDateString(),
                'effective_to' => now()->addMonth()->toDateString(),
                'notes' => 'DOH allocation 2026-Q4',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'government_subsidy')
            ->assertJsonPath('data.value', null)
            ->assertJsonPath('data.is_active_now', true);

        $this->assertDatabaseHas('billing_programs', [
            'facility_id' => $this->supervisor->facility_id,
            'name' => 'DOH fourth-quarter subsidy',
            'kind' => 'government_subsidy',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.program_created', 'actor_id' => $this->supervisor->id]);
    }

    public function test_it_keeps_discount_and_promo_types_with_their_value_and_date_window(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/billing-programs', [
                'name' => 'National Blood Month',
                'kind' => 'percentage_discount',
                'value' => 15,
                'effective_from' => '2026-11-01',
                'effective_to' => '2026-11-30',
            ])
            ->assertCreated()
            ->assertJsonPath('data.value', 15)
            ->assertJsonPath('data.is_active_now', false);

        $program = BillingProgram::query()->sole();
        $this->actingAs($this->supervisor)
            ->putJson("/api/blood-center/billing-programs/{$program->id}", [
                'name' => 'National Blood Month',
                'kind' => 'fixed_discount',
                'value' => 500,
                'effective_from' => '2026-11-01',
                'effective_to' => '2026-11-30',
                'is_enabled' => false,
                'notes' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.kind', 'fixed_discount')
            ->assertJsonPath('data.value', 500)
            ->assertJsonPath('data.is_enabled', false);
    }

    public function test_only_the_billing_supervisor_can_manage_programs(): void
    {
        $facility = $this->supervisor->facility;
        foreach ([
            User::factory()->bloodCenterStaff($facility, StaffRole::BillingClerk)->create(),
            User::factory()->bloodCenterStaff($facility, StaffRole::InventoryControlOfficer)->create(),
            User::factory()->bloodCenterCustomStaff($facility, Department::Billing)->create(),
        ] as $staff) {
            $this->actingAs($staff)->getJson('/api/blood-center/billing-programs')->assertForbidden();
            $this->actingAs($staff)->postJson('/api/blood-center/billing-programs', [])->assertForbidden();
        }
    }
}
