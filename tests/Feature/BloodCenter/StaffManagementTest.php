<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\RoleName;
use App\Enums\StaffRole;
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
            'staff_role' => StaffRole::SerologyTechnologist->value,
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
            ->assertJsonPath('data.staff_role', 'serology_technologist')
            ->assertJsonPath('data.department', 'testing')
            ->assertJsonPath('data.is_supervisor', false)
            ->assertJsonPath('data.account_status', AccountStatus::PendingVerification->value)
            ->assertJsonPath('data.email_verified', false);

        $created = User::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertSame($this->facility->id, $created->facility_id, 'facility_id must come from the actor.');
        $this->assertTrue($created->hasRole(RoleName::BloodCenter));
        $this->assertSame(StaffRole::SerologyTechnologist, $created->staff_role);
        $this->assertSame(Department::Testing, $created->department);
    }

    public function test_a_role_and_a_department_that_disagree_are_refused(): void
    {
        // A predefined role decides its department; naming another one is a
        // mistake to point out, not a department to file the account under.
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'staff_role' => StaffRole::BillingClerk->value,
                'department' => Department::Collection->value,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_role');
    }

    public function test_a_custom_role_is_created_in_the_department_chosen(): void
    {
        $response = $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'staff_role' => null,
                'custom_role' => 'Quality Assurance Officer',
                'department' => Department::Issuance->value,
                'position' => 'RMT',
                'staff_privileges' => ['read', 'update'],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.role_label', 'Quality Assurance Officer')
            ->assertJsonPath('data.department', 'issuance')
            ->assertJsonPath('data.staff_privileges', ['read', 'update']);

        $created = User::where('uuid', $response->json('data.uuid'))->sole();

        $this->assertNull($created->staff_role);
        $this->assertContains('inventory.view', $created->abilities());
        $this->assertContains('inventory.update', $created->abilities());
        $this->assertNotContains('inventory.create', $created->abilities());
        $this->assertNotContains('inventory.discard', $created->abilities());
    }

    public function test_a_custom_role_needs_a_department(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'staff_role' => null,
                'custom_role' => 'Quality Assurance Officer',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('department');
    }

    public function test_a_typed_role_that_names_a_predefined_one_becomes_it(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'staff_role' => null,
                'custom_role' => 'laboratory supervisor',
                'department' => Department::Testing->value,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.staff_role', 'lab_supervisor')
            ->assertJsonPath('data.custom_role', null);
    }

    public function test_privileges_are_checked_and_changes_to_them_are_audited(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload(['staff_privileges' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_privileges');

        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload(['staff_privileges' => ['read', 'erase']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_privileges.1');

        $staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::InventoryControlOfficer)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", ['staff_privileges' => ['read']])
            ->assertOk()
            ->assertJsonPath('data.staff_privileges', ['read']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.privileges_changed', 'auditable_id' => $staff->id]);
    }

    public function test_the_role_catalogue_is_served_to_the_supervisor(): void
    {
        $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/staff/roles')
            ->assertOk()
            ->assertJsonPath('data.departments.0.department', 'collection')
            ->assertJsonPath('data.departments.0.label', 'Donor/Collection')
            ->assertJsonPath('data.departments.0.roles.0.key', 'screening_physician')
            ->assertJsonPath('data.privileges.0.key', 'read')
            ->assertJsonCount(count(Department::cases()), 'data.departments');
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

    public function test_a_non_supervisor_must_be_given_a_role(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload(['staff_role' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_role');
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload(['staff_role' => 'radiologist']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_role');
    }

    public function test_a_supervisor_may_be_created_without_a_role(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/blood-center/staff', $this->payload([
                'staff_role' => null,
                'is_supervisor' => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.staff_role', null)
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
        $testing = User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create();
        User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create();

        $rows = $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/staff?department=testing')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($testing->uuid, $rows[0]['uuid']);
    }

    public function test_the_roster_filters_by_role(): void
    {
        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();

        $rows = $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/staff?staff_role=screening_physician')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($physician->uuid, $rows[0]['uuid']);
    }

    public function test_a_supervisor_reassigns_a_colleagues_role(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'staff_role' => StaffRole::MedicalReceptionist->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.staff_role', 'medical_receptionist')
            ->assertJsonPath('data.department', 'collection');

        $this->assertSame(Department::Collection, $staff->fresh()->department);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.role_changed',
            'actor_id' => $this->supervisor->id,
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_an_update_cannot_strand_a_non_supervisor_without_a_role(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", ['staff_role' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_role');

        $this->assertNotNull($staff->fresh()->staff_role);
    }

    public function test_clearing_a_role_is_allowed_when_the_same_request_grants_supervisor(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'staff_role' => null,
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
