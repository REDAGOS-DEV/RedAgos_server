<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\ManagementLevel;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Repository\StaffRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CompilesPostgresQueries;
use Tests\TestCase;

/**
 * A facility must never be left with nobody able to manage its staff.
 *
 * Demotion, deactivation and deletion each remove an Admin, and any of the
 * three performed on the last one would lock the centre out of its own roster
 * with no in-app way back. A view-only Supervisor cannot manage anyone, so
 * only the Admin level counts. The guard counts after the write inside the
 * transaction and lets the rollback undo it, which is the only ordering that
 * answers "would this leave zero" without duplicating the write's own logic.
 *
 * Counting alone is not enough, though: two demotions running at once would
 * both read "two Admins" and both proceed. The count is therefore taken under
 * the facility row lock. As elsewhere in this module, the lock itself cannot
 * be exercised under sqlite :memory:, where lockForUpdate() compiles to
 * nothing — it is proven structurally instead, and what the sqlite tests prove
 * is the predicate.
 */
class StaffAdminFloorTest extends TestCase
{
    use CompilesPostgresQueries, LazilyRefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->admin = User::factory()->bloodCenterAdmin($this->facility)->create();
    }

    public function test_the_last_admin_cannot_demote_themselves(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$this->admin->uuid}", [
                'management_level' => null,
                'staff_role' => StaffRole::InventoryControlOfficer->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_admin');

        $this->assertTrue($this->admin->fresh()->isCenterAdmin(), 'The demotion must be rolled back.');
    }

    public function test_the_last_admin_cannot_step_down_to_supervisor(): void
    {
        // A Supervisor watches but manages nobody, so this would strand the
        // roster as surely as removing the level outright.
        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$this->admin->uuid}", [
                'management_level' => ManagementLevel::Supervisor->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_admin');

        $this->assertTrue($this->admin->fresh()->isCenterAdmin());
    }

    public function test_the_rollback_undoes_every_field_in_the_refused_request(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$this->admin->uuid}", [
                'management_level' => null,
                'staff_role' => StaffRole::InventoryControlOfficer->value,
                'position' => 'Demoted Clerk',
            ])
            ->assertStatus(409);

        // The write happens before the count, so the whole transaction must
        // roll back — not just the level that tripped the guard.
        $fresh = $this->admin->fresh();
        $this->assertTrue($fresh->isCenterAdmin());
        $this->assertNotSame('Demoted Clerk', $fresh->position);
        $this->assertNull($fresh->department);
    }

    public function test_the_last_admin_cannot_be_deactivated(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$this->admin->uuid}", [
                'account_status' => AccountStatus::Deactivated->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_admin');

        $this->assertSame(AccountStatus::Active, $this->admin->fresh()->account_status);
    }

    public function test_the_last_admin_cannot_be_suspended(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$this->admin->uuid}", [
                'account_status' => AccountStatus::Suspended->value,
            ])
            ->assertStatus(409);

        $this->assertSame(AccountStatus::Active, $this->admin->fresh()->account_status);
    }

    public function test_the_last_admin_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/blood-center/staff/{$this->admin->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_admin');

        $this->assertNotSoftDeleted('users', ['id' => $this->admin->id]);
    }

    public function test_a_supervisor_does_not_hold_the_floor(): void
    {
        User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->assertSame(1, app(StaffRepository::class)->countActiveAdmins($this->facility->id));

        $this->actingAs($this->admin)
            ->deleteJson("/api/blood-center/staff/{$this->admin->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_admin');
    }

    public function test_an_admin_may_be_demoted_once_a_second_one_exists(): void
    {
        $second = User::factory()->bloodCenterAdmin($this->facility)->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$second->uuid}", [
                'management_level' => null,
                'staff_role' => StaffRole::SerologyTechnologist->value,
            ])
            ->assertOk();

        $this->assertNull($second->fresh()->management_level);
        $this->assertTrue($this->admin->fresh()->isCenterAdmin());
    }

    public function test_promoting_a_replacement_first_then_stepping_down_works(): void
    {
        $successor = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$successor->uuid}", ['management_level' => 'admin'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$this->admin->uuid}", [
                'management_level' => null,
                'staff_role' => StaffRole::BillingClerk->value,
            ])
            ->assertOk();

        $this->assertTrue($successor->fresh()->isCenterAdmin());
        $this->assertNull($this->admin->fresh()->management_level);
    }

    public function test_a_deactivated_admin_does_not_hold_the_floor(): void
    {
        // Holds the level but cannot sign in, so cannot manage anyone. Counting
        // them would leave the centre locked out while looking staffed.
        $dormant = User::factory()->bloodCenterAdmin($this->facility)->create([
            'account_status' => AccountStatus::Deactivated,
        ]);

        $this->assertSame(
            1,
            app(StaffRepository::class)->countActiveAdmins($this->facility->id),
            'Only the account that can still sign in counts.'
        );

        $this->actingAs($this->admin)
            ->deleteJson("/api/blood-center/staff/{$this->admin->uuid}")
            ->assertStatus(409);

        $this->assertNotNull($dormant->fresh());
    }

    public function test_a_soft_deleted_admin_does_not_hold_the_floor(): void
    {
        User::factory()->bloodCenterAdmin($this->facility)->create()->delete();

        $this->assertSame(1, app(StaffRepository::class)->countActiveAdmins($this->facility->id));

        $this->actingAs($this->admin)
            ->deleteJson("/api/blood-center/staff/{$this->admin->uuid}")
            ->assertStatus(409);
    }

    public function test_an_admin_at_another_facility_does_not_hold_this_ones_floor(): void
    {
        User::factory()
            ->bloodCenterAdmin(Facility::factory()->approved()->create())
            ->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/blood-center/staff/{$this->admin->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_admin');
    }

    public function test_an_update_takes_the_facility_lock_before_counting(): void
    {
        $second = User::factory()->bloodCenterAdmin($this->facility)->create();

        $this->partialMock(
            BloodCenterRepository::class,
            fn ($mock) => $mock->shouldReceive('lockFacility')->once()->passthru()
        );

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$second->uuid}", ['management_level' => null, 'staff_role' => 'billing_clerk'])
            ->assertOk();
    }

    public function test_a_delete_takes_the_facility_lock_before_counting(): void
    {
        $second = User::factory()->bloodCenterAdmin($this->facility)->create();

        $this->partialMock(
            BloodCenterRepository::class,
            fn ($mock) => $mock->shouldReceive('lockFacility')->once()->passthru()
        );

        $this->actingAs($this->admin)
            ->deleteJson("/api/blood-center/staff/{$second->uuid}")
            ->assertOk();
    }

    public function test_the_facility_lock_really_locks(): void
    {
        // Two demotions arriving together would otherwise both read "two
        // Admins" and both succeed. sqlite compiles lockForUpdate() away, so
        // the guarantee is checked against the deployed grammar instead.
        $queries = $this->compiledOnPostgres(
            fn () => app(BloodCenterRepository::class)->lockFacility($this->facility->id)
        );

        $this->assertStringEndsWith('for update', $queries[0]['query']);
    }
}
