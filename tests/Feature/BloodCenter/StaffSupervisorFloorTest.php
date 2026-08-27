<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Models\Facility;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Repository\StaffRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CompilesPostgresQueries;
use Tests\TestCase;

/**
 * A facility must never be left with nobody able to manage it.
 *
 * Demotion, deactivation and deletion each remove a supervisor, and any of the
 * three performed on the last one would lock the centre out of its own roster
 * with no in-app way back. The guard counts after the write inside the
 * transaction and lets the rollback undo it, which is the only ordering that
 * answers "would this leave zero" without duplicating the write's own logic.
 *
 * Counting alone is not enough, though: two demotions running at once would
 * both read "two supervisors" and both proceed. The count is therefore taken
 * under the facility row lock. As elsewhere in this module, the lock itself
 * cannot be exercised under sqlite :memory:, where lockForUpdate() compiles to
 * nothing — it is proven structurally instead, and what the sqlite tests prove
 * is the predicate.
 */
class StaffSupervisorFloorTest extends TestCase
{
    use CompilesPostgresQueries, LazilyRefreshDatabase;

    private Facility $facility;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();
    }

    public function test_the_last_supervisor_cannot_demote_themselves(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$this->supervisor->uuid}", [
                'is_supervisor' => false,
                'department' => Department::Inventory->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_supervisor');

        $this->assertTrue($this->supervisor->fresh()->is_supervisor, 'The demotion must be rolled back.');
    }

    public function test_the_rollback_undoes_every_field_in_the_refused_request(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$this->supervisor->uuid}", [
                'is_supervisor' => false,
                'department' => Department::Inventory->value,
                'position' => 'Demoted Clerk',
            ])
            ->assertStatus(409);

        // The write happens before the count, so the whole transaction must
        // roll back — not just the flag that tripped the guard.
        $fresh = $this->supervisor->fresh();
        $this->assertTrue($fresh->is_supervisor);
        $this->assertNotSame('Demoted Clerk', $fresh->position);
        $this->assertNull($fresh->department);
    }

    public function test_the_last_supervisor_cannot_be_deactivated(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$this->supervisor->uuid}", [
                'account_status' => AccountStatus::Deactivated->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_supervisor');

        $this->assertSame(AccountStatus::Active, $this->supervisor->fresh()->account_status);
    }

    public function test_the_last_supervisor_cannot_be_suspended(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$this->supervisor->uuid}", [
                'account_status' => AccountStatus::Suspended->value,
            ])
            ->assertStatus(409);

        $this->assertSame(AccountStatus::Active, $this->supervisor->fresh()->account_status);
    }

    public function test_the_last_supervisor_cannot_be_deleted(): void
    {
        $this->actingAs($this->supervisor)
            ->deleteJson("/api/blood-center/staff/{$this->supervisor->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_supervisor');

        $this->assertNotSoftDeleted('users', ['id' => $this->supervisor->id]);
    }

    public function test_a_supervisor_may_be_demoted_once_a_second_one_exists(): void
    {
        $second = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$second->uuid}", [
                'is_supervisor' => false,
                'department' => Department::Laboratory->value,
            ])
            ->assertOk();

        $this->assertFalse($second->fresh()->is_supervisor);
        $this->assertTrue($this->supervisor->fresh()->is_supervisor);
    }

    public function test_promoting_a_replacement_first_then_stepping_down_works(): void
    {
        $successor = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$successor->uuid}", ['is_supervisor' => true])
            ->assertOk();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$this->supervisor->uuid}", [
                'is_supervisor' => false,
                'department' => Department::Billing->value,
            ])
            ->assertOk();

        $this->assertTrue($successor->fresh()->is_supervisor);
        $this->assertFalse($this->supervisor->fresh()->is_supervisor);
    }

    public function test_a_deactivated_supervisor_does_not_hold_the_floor(): void
    {
        // Holds the flag but cannot sign in, so cannot manage anyone. Counting
        // them would leave the centre locked out while looking staffed.
        $dormant = User::factory()->bloodCenterSupervisor($this->facility)->create([
            'account_status' => AccountStatus::Deactivated,
        ]);

        $this->assertSame(
            1,
            app(StaffRepository::class)->countActiveSupervisors($this->facility->id),
            'Only the account that can still sign in counts.'
        );

        $this->actingAs($this->supervisor)
            ->deleteJson("/api/blood-center/staff/{$this->supervisor->uuid}")
            ->assertStatus(409);

        $this->assertNotNull($dormant->fresh());
    }

    public function test_a_soft_deleted_supervisor_does_not_hold_the_floor(): void
    {
        User::factory()->bloodCenterSupervisor($this->facility)->create()->delete();

        $this->assertSame(1, app(StaffRepository::class)->countActiveSupervisors($this->facility->id));

        $this->actingAs($this->supervisor)
            ->deleteJson("/api/blood-center/staff/{$this->supervisor->uuid}")
            ->assertStatus(409);
    }

    public function test_a_supervisor_at_another_facility_does_not_hold_this_ones_floor(): void
    {
        User::factory()
            ->bloodCenterSupervisor(Facility::factory()->approved()->create())
            ->create();

        $this->actingAs($this->supervisor)
            ->deleteJson("/api/blood-center/staff/{$this->supervisor->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_supervisor');
    }

    public function test_an_update_takes_the_facility_lock_before_counting(): void
    {
        $second = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->partialMock(
            BloodCenterRepository::class,
            fn ($mock) => $mock->shouldReceive('lockFacility')->once()->passthru()
        );

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$second->uuid}", ['is_supervisor' => false, 'department' => 'billing'])
            ->assertOk();
    }

    public function test_a_delete_takes_the_facility_lock_before_counting(): void
    {
        $second = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->partialMock(
            BloodCenterRepository::class,
            fn ($mock) => $mock->shouldReceive('lockFacility')->once()->passthru()
        );

        $this->actingAs($this->supervisor)
            ->deleteJson("/api/blood-center/staff/{$second->uuid}")
            ->assertOk();
    }

    public function test_the_facility_lock_really_locks(): void
    {
        // Two demotions arriving together would otherwise both read "two
        // supervisors" and both succeed. sqlite compiles lockForUpdate() away,
        // so the guarantee is checked against the deployed grammar instead.
        $queries = $this->compiledOnPostgres(
            fn () => app(BloodCenterRepository::class)->lockFacility($this->facility->id)
        );

        $this->assertStringEndsWith('for update', $queries[0]['query']);
    }
}
