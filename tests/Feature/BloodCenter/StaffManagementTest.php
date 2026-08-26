<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();
    }

    /**
     * A valid creation payload, overridable per test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Maria',
            'last_name' => 'Guerra',
            'email' => 'maria.guerra@example.com',
            'phone' => '09171234567',
            'position' => 'Medical Technologist',
            'department' => Department::Laboratory->value,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            ...$overrides,
        ];
    }

    public function test_a_supervisor_creates_a_colleague_at_their_own_facility(): void
    {
        $response = $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.department', 'laboratory')
            ->assertJsonPath('data.is_supervisor', false)
            ->assertJsonPath('data.account_status', AccountStatus::PendingVerification->value)
            ->assertJsonPath('data.email_verified', false);

        $created = User::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertSame($this->facility->id, $created->facility_id, 'facility_id must come from the actor.');
        $this->assertTrue($created->hasRole(RoleName::BloodCenter));
        $this->assertSame(Department::Laboratory, $created->department);
    }

    public function test_a_created_account_never_takes_a_facility_from_request_input(): void
    {
        $other = Facility::factory()->approved()->create();

        $response = $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'facility_id' => $other->id,
                'is_supervisor' => true,
            ]))
            ->assertCreated();

        $created = User::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertSame($this->facility->id, $created->facility_id);
    }

    public function test_a_new_account_is_sent_a_verification_link(): void
    {
        $response = $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload())
            ->assertCreated();

        $created = User::where('uuid', $response->json('data.uuid'))->firstOrFail();

        Notification::assertSentTo($created, VerifyEmailNotification::class);
    }

    public function test_a_non_supervisor_must_be_given_a_department(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload(['department' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('department');
    }

    public function test_a_supervisor_may_be_created_without_a_department(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'department' => null,
                'is_supervisor' => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.is_supervisor', true);
    }

    public function test_employee_ids_are_unique_per_facility_not_globally(): void
    {
        $otherFacility = Facility::factory()->approved()->create();
        User::factory()->bloodCenterStaff($otherFacility)->create(['employee_id' => 'EMP-001']);

        // The same badge number at a different centre must not collide.
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload(['employee_id' => 'EMP-001']))
            ->assertCreated();

        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'email' => 'second@example.com',
                'phone' => '09171234568',
                'employee_id' => 'EMP-001',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload(['email' => 'taken@example.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_roster_lists_only_the_callers_own_facility(): void
    {
        $mine = User::factory()->bloodCenterStaff($this->facility)->create();

        $otherFacility = Facility::factory()->approved()->create();
        $theirs = User::factory()->bloodCenterStaff($otherFacility)->create();

        $uuids = collect(
            $this->actingAs($this->supervisor)
                ->getJson('/api/blood-center/staff')
                ->assertOk()
                ->json('data')
        )->pluck('uuid');

        $this->assertContains($mine->uuid, $uuids);
        $this->assertContains($this->supervisor->uuid, $uuids);
        $this->assertNotContains($theirs->uuid, $uuids);
    }

    public function test_the_roster_filters_by_department(): void
    {
        $laboratory = User::factory()->bloodCenterStaff($this->facility, Department::Laboratory)->create();
        User::factory()->bloodCenterStaff($this->facility, Department::Billing)->create();

        $rows = $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/staff?department=laboratory')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($laboratory->uuid, $rows[0]['uuid']);
    }

    public function test_a_supervisor_reassigns_a_colleagues_department(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility, Department::Billing)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'department' => Department::Collection->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.department', 'collection');

        $this->assertSame(Department::Collection, $staff->fresh()->department);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.department_changed',
            'actor_id' => $this->supervisor->id,
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_an_update_cannot_strand_a_non_supervisor_without_a_department(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", ['department' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('department');

        $this->assertNotNull($staff->fresh()->department);
    }

    public function test_clearing_a_department_is_allowed_when_the_same_request_grants_supervisor(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'department' => null,
                'is_supervisor' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_supervisor', true);
    }

    public function test_deactivating_a_colleague_revokes_their_tokens(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();
        $staff->createToken('api-token');

        $this->assertSame(1, $staff->tokens()->count());

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'account_status' => AccountStatus::Deactivated->value,
            ])
            ->assertOk();

        // account_status is only checked at login, so a live token would
        // otherwise outlive the deactivation.
        $this->assertSame(0, $staff->fresh()->tokens()->count());
        $this->assertTrue($staff->fresh()->hasRole(RoleName::BloodCenter), 'The role stays attached.');
    }

    public function test_deleting_a_colleague_soft_deletes_and_revokes_tokens(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();
        $staff->createToken('api-token');

        $this->actingAs($this->supervisor)
            ->deleteJson("/api/blood-center/staff/{$staff->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_a_removed_colleague_can_be_restored(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();
        $staff->delete();

        $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/staff/{$staff->uuid}/restore")
            ->assertOk();

        $this->assertNotSoftDeleted('users', ['id' => $staff->id]);
    }

    public function test_restoring_an_active_account_is_refused(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/staff/{$staff->uuid}/restore")
            ->assertStatus(409)
            ->assertJsonPath('code', 'staff_not_deleted');
    }
}
