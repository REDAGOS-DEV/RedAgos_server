<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Service\CashSessionService;
use App\Support\DepartmentPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Several roles per staff member (project owner, 2026-10-17).
 *
 * What is locked in: a person holds every ability of every role they have,
 * capped by their one set of privileges; all their roles sit in one
 * department; a role that qualifies its holder (the department's correction
 * approver, the Billing Supervisor) qualifies them whether it is their main
 * role or another; and a custom role may stand beside listed ones.
 */
class StaffMultipleRolesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->admin = User::factory()->bloodCenterAdmin($this->facility)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'email' => 'ana.reyes@example.com',
            'staff_role' => StaffRole::MedicalReceptionist->value,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            ...$overrides,
        ];
    }

    private function staffWith(StaffRole $main, array $others = [], ?string $custom = null): User
    {
        $user = User::factory()->bloodCenterStaff($this->facility, $main)->create();
        $user->additional_staff_roles = array_map(fn (StaffRole $r): string => $r->value, $others);
        $user->custom_role = $custom;
        $user->save();

        return $user->fresh();
    }

    public function test_a_colleague_is_created_with_two_roles_and_does_both_jobs(): void
    {
        $body = $this->actingAs($this->admin)
            ->postJson('/api/blood-center/staff', $this->payload([
                'additional_staff_roles' => [StaffRole::Phlebotomist->value],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.staff_role', 'medical_receptionist')
            ->assertJsonPath('data.staff_roles', ['medical_receptionist', 'phlebotomist'])
            ->assertJsonPath('data.additional_staff_roles', ['phlebotomist'])
            ->assertJsonPath('data.department', 'collection')
            ->assertJsonPath('data.role_label', StaffRole::MedicalReceptionist->label().', '.StaffRole::Phlebotomist->label())
            ->json('data');

        // The receptionist's register, the chair's collect.
        $this->assertContains('donations.register', $body['permissions']);
        $this->assertContains('donations.collect', $body['permissions']);
        $this->assertContains('donors.manage', $body['permissions']);
    }

    public function test_roles_from_two_departments_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/blood-center/staff', $this->payload([
                'additional_staff_roles' => [StaffRole::BillingClerk->value],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('additional_staff_roles');

        $this->assertDatabaseMissing('users', ['email' => 'ana.reyes@example.com']);
    }

    public function test_a_custom_role_may_stand_beside_a_listed_one(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/blood-center/staff', $this->payload([
                'staff_role' => StaffRole::ProcessingAssistant->value,
                'custom_role' => 'Quality Lead',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.staff_roles', ['processing_assistant'])
            ->assertJsonPath('data.custom_role', 'Quality Lead')
            ->assertJsonPath('data.role_labels', [StaffRole::ProcessingAssistant->label(), 'Quality Lead']);
    }

    public function test_a_typed_role_naming_a_listed_one_beside_a_main_role_becomes_one_of_their_roles(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/blood-center/staff', $this->payload([
                'custom_role' => 'phlebotomist',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.staff_roles', ['medical_receptionist', 'phlebotomist'])
            ->assertJsonPath('data.custom_role', null);
    }

    public function test_one_set_of_privileges_caps_every_role(): void
    {
        $body = $this->actingAs($this->admin)
            ->postJson('/api/blood-center/staff', $this->payload([
                'additional_staff_roles' => [StaffRole::Phlebotomist->value],
                'staff_privileges' => ['read'],
            ]))
            ->assertCreated()
            ->json('data.permissions');

        $this->assertContains('donors.view', $body);
        $this->assertNotContains('donations.collect', $body);
        $this->assertNotContains('donors.manage', $body);
    }

    public function test_adding_and_removing_a_role_is_audited_with_both_lists(): void
    {
        $staff = $this->staffWith(StaffRole::MedicalReceptionist);

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", [
                'staff_role' => StaffRole::MedicalReceptionist->value,
                'additional_staff_roles' => [StaffRole::Phlebotomist->value, StaffRole::ApheresisSpecialist->value],
                'custom_role' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.staff_roles', ['medical_receptionist', 'phlebotomist', 'apheresis_specialist']);

        $log = AuditLog::where('action', 'staff.role_changed')->latest('id')->firstOrFail();
        $this->assertSame(['medical_receptionist'], $log->context['from']);
        $this->assertSame(['medical_receptionist', 'phlebotomist', 'apheresis_specialist'], $log->context['to']);

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", ['additional_staff_roles' => []])
            ->assertOk()
            ->assertJsonPath('data.staff_roles', ['medical_receptionist']);
    }

    public function test_a_main_role_from_another_department_cannot_keep_the_old_roles(): void
    {
        $staff = $this->staffWith(StaffRole::MedicalReceptionist, [StaffRole::Phlebotomist]);

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$staff->uuid}", ['staff_role' => StaffRole::BillingClerk->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('additional_staff_roles');

        $this->assertSame(StaffRole::MedicalReceptionist, $staff->fresh()->staff_role);
    }

    public function test_the_model_never_stores_a_role_from_another_department(): void
    {
        $staff = $this->staffWith(StaffRole::MedicalReceptionist, [StaffRole::Phlebotomist, StaffRole::BillingClerk, StaffRole::MedicalReceptionist]);

        $this->assertSame(['phlebotomist'], $staff->additional_staff_roles);
        $this->assertSame(Department::Collection, $staff->department);
    }

    public function test_a_qualifying_role_counts_when_it_is_not_the_main_one(): void
    {
        $clerk = $this->staffWith(StaffRole::BillingClerk, [StaffRole::BillingSupervisor]);

        $this->assertTrue($clerk->hasStaffRole(StaffRole::BillingSupervisor));
        $this->assertTrue(app(CashSessionService::class)->mayOversee($clerk));
        $this->assertContains('billing.prices', DepartmentPermissions::for($clerk));
        $this->assertContains('corrections.approve', DepartmentPermissions::for($clerk));
    }

    public function test_the_correction_queue_reaches_a_head_by_their_other_role(): void
    {
        $head = $this->staffWith(StaffRole::MedicalReceptionist, [StaffRole::ScreeningPhysician]);

        $this->actingAs($head)
            ->getJson('/api/blood-center/corrections?scope=review')
            ->assertOk();

        $this->assertContains('corrections.approve', $head->abilities());
    }

    public function test_the_roster_filter_finds_a_role_held_as_another_role(): void
    {
        $both = $this->staffWith(StaffRole::MedicalReceptionist, [StaffRole::Phlebotomist]);
        $only = $this->staffWith(StaffRole::MedicalReceptionist);

        $uuids = collect($this->actingAs($this->admin)
            ->getJson('/api/blood-center/staff?staff_role=phlebotomist')
            ->assertOk()
            ->json('data'))->pluck('uuid');

        $this->assertContains($both->uuid, $uuids);
        $this->assertNotContains($only->uuid, $uuids);
    }

    public function test_the_signed_in_user_carries_every_role(): void
    {
        $staff = $this->staffWith(StaffRole::MedicalReceptionist, [StaffRole::Phlebotomist]);

        $this->actingAs($staff)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.staff_roles', ['medical_receptionist', 'phlebotomist']);
    }
}
