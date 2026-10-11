<?php

namespace Tests\Feature\HospitalInventory;

use App\Enums\HospitalRole;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A hospital blood bank's Head keeps its staff (project owner, 2026-10-11).
 *
 * What is locked in: the Head adds a colleague with a role, who holds the
 * blood_bank role at the Head's blood bank and is sent a verification link;
 * the Head changes roles, removes and restores accounts; the blood bank is
 * never left without a Head; nobody else may do any of it; and another blood
 * bank's account is not found.
 */
class HospitalStaffTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $hospital;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->head = User::factory()->bloodBankStaff($this->hospital)->create();
    }

    public function test_the_head_adds_a_colleague_with_a_role(): void
    {
        Notification::fake();

        $this->actingAs($this->head)
            ->postJson('/api/hospital/staff', [
                'first_name' => 'Rosa',
                'last_name' => 'Lim',
                'email' => ' Rosa.Lim@Hospital.ph ',
                'hospital_role' => HospitalRole::InventoryStaff->value,
                'position' => 'RMT',
                'password' => 'Secret123',
                'password_confirmation' => 'Secret123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.hospital_role', 'inventory_staff')
            ->assertJsonPath('data.hospital_role_label', 'Inventory Staff')
            ->assertJsonPath('data.email', 'rosa.lim@hospital.ph');

        $colleague = User::query()->where('email', 'rosa.lim@hospital.ph')->sole();
        $this->assertSame($this->hospital->id, $colleague->facility_id);
        $this->assertSame(HospitalRole::InventoryStaff, $colleague->hospital_role);
        $this->assertTrue($colleague->hasRole(RoleName::BloodBank));
        $this->assertContains('hospital.inventory.stock_in', $colleague->abilities());
        Notification::assertSentTo($colleague, VerifyEmailNotification::class);

        $this->assertDatabaseHas('audit_logs', ['action' => 'hospital_staff.created', 'actor_id' => $this->head->id]);
    }

    public function test_the_roster_lists_the_blood_banks_staff_and_the_roles(): void
    {
        User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::RequestingStaff)->create();
        User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->create();

        $this->actingAs($this->head)
            ->getJson('/api/hospital/staff')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(3, 'roles')
            ->assertJsonPath('roles.0.value', 'head');
    }

    public function test_the_head_changes_a_role_removes_and_restores(): void
    {
        $colleague = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::RequestingStaff)->create();

        $this->actingAs($this->head)
            ->patchJson("/api/hospital/staff/{$colleague->uuid}", ['hospital_role' => 'inventory_staff'])
            ->assertOk()
            ->assertJsonPath('data.hospital_role', 'inventory_staff');
        $this->assertDatabaseHas('audit_logs', ['action' => 'hospital_staff.role_changed']);

        $this->actingAs($this->head)->deleteJson("/api/hospital/staff/{$colleague->uuid}")->assertOk();
        $this->assertSoftDeleted($colleague);

        $this->actingAs($this->head)
            ->postJson("/api/hospital/staff/{$colleague->uuid}/restore")
            ->assertOk()
            ->assertJsonPath('data.hospital_role', 'inventory_staff');
        $this->assertNotSoftDeleted($colleague);
    }

    public function test_the_blood_bank_always_keeps_a_head(): void
    {
        $this->actingAs($this->head)
            ->patchJson("/api/hospital/staff/{$this->head->uuid}", ['hospital_role' => 'inventory_staff'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_head');

        $this->actingAs($this->head)
            ->deleteJson("/api/hospital/staff/{$this->head->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_head');

        $this->assertSame(HospitalRole::Head, $this->head->fresh()->hospital_role);
        $this->assertNotSoftDeleted($this->head);

        // With a second Head, the first may step down.
        $second = User::factory()->bloodBankStaff($this->hospital)->create();
        $this->actingAs($this->head)
            ->patchJson("/api/hospital/staff/{$this->head->uuid}", ['hospital_role' => 'requesting_staff'])
            ->assertOk();
        $this->assertSame(HospitalRole::Head, $second->fresh()->hospital_role);
    }

    public function test_only_the_head_keeps_the_staff(): void
    {
        $colleague = User::factory()->bloodBankStaff($this->hospital)->hospitalRole(HospitalRole::InventoryStaff)->create();

        $this->actingAs($colleague)->getJson('/api/hospital/staff')->assertForbidden();
        $this->actingAs($colleague)->patchJson("/api/hospital/staff/{$colleague->uuid}", ['hospital_role' => 'head'])->assertForbidden();
        $this->assertSame(HospitalRole::InventoryStaff, $colleague->fresh()->hospital_role);
    }

    public function test_another_blood_banks_account_is_not_found(): void
    {
        $elsewhere = User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->hospitalRole(HospitalRole::InventoryStaff)->create();

        $this->actingAs($this->head)
            ->patchJson("/api/hospital/staff/{$elsewhere->uuid}", ['hospital_role' => 'requesting_staff'])
            ->assertNotFound()
            ->assertJsonPath('code', 'staff_not_found');
    }

    public function test_a_new_colleague_needs_a_valid_role_and_a_strong_password(): void
    {
        $this->actingAs($this->head)
            ->postJson('/api/hospital/staff', [
                'first_name' => 'Rosa',
                'last_name' => 'Lim',
                'email' => $this->head->email,
                'hospital_role' => 'nurse',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertJsonValidationErrors(['email', 'hospital_role', 'password']);
    }
}
