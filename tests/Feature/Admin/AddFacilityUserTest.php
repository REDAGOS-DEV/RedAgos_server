<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\AddFacilityUser;
use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\HospitalRole;
use App\Enums\ManagementLevel;
use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Staffing a facility that has no account to staff it with.
 *
 * The roster endpoint resolves the facility from its caller, so it cannot serve
 * a facility whose first account does not exist — the seeded blood centres are
 * exactly that case. These tests pin the two things the command decides on its
 * own as a result: which access role it grants, and whether a staff role means
 * anything for the type of facility in question.
 */
class AddFacilityUserTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const ACCOUNT = [
        '--first-name' => 'Jomar',
        '--last-name' => 'Reyes',
        '--email' => 'jomar@redagos.test',
        '--password' => 'Sup3rSecret',
    ];

    private User $admin;

    private Facility $bloodCenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->withRole(RoleName::Admin)->create();
        $this->bloodCenter = Facility::factory()->approved()->create();
    }

    public function test_it_adds_a_staff_member_in_a_role_to_a_blood_center(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--role' => 'inventory_control_officer',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertSame($this->bloodCenter->id, $staff->facility_id);
        $this->assertSame(StaffRole::InventoryControlOfficer, $staff->staff_role);
        $this->assertSame(Department::Issuance, $staff->department);
        $this->assertNull($staff->management_level);
        $this->assertTrue($staff->hasRole(RoleName::BloodCenter));
        $this->assertSame(AccountStatus::Active, $staff->account_status);
        $this->assertTrue($staff->hasVerifiedEmail());
        $this->assertNotNull($staff->username);
    }

    public function test_level_grants_a_management_level_without_a_posting(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--level' => 'admin',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertSame(ManagementLevel::Admin, $staff->management_level);
        $this->assertNull($staff->staff_role);
        $this->assertNull($staff->department);
        $this->assertContains('staff.manage', $staff->abilities());
    }

    public function test_a_level_may_sit_on_top_of_a_role(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--level' => 'supervisor',
            '--role' => 'lab_supervisor',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertSame(ManagementLevel::Supervisor, $staff->management_level);
        $this->assertSame(StaffRole::LabSupervisor, $staff->staff_role);
        $this->assertSame(Department::Testing, $staff->department);
    }

    public function test_an_unknown_level_creates_nothing(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--level' => 'owner',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_a_blood_bank_has_no_management_level(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => Facility::factory()->bloodBank()->approved()->create()->id,
            '--level' => 'admin',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_a_blood_center_account_must_be_posted_or_promoted(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--no-interaction' => true,
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_an_interactive_run_asks_for_the_role(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            ...self::ACCOUNT,
        ])->expectsChoice('Role', 'serology_technologist', AddFacilityUser::levelChoices())->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertSame(StaffRole::SerologyTechnologist, $staff->staff_role);
        $this->assertSame(Department::Testing, $staff->department);
        $this->assertNull($staff->management_level);
    }

    public function test_the_prompt_offers_every_role_and_both_management_levels(): void
    {
        $choices = AddFacilityUser::levelChoices();

        $this->assertSame([...StaffRole::values(), 'admin', 'supervisor'], array_keys($choices));
        $this->assertSame('Testing — Laboratory Supervisor', $choices['lab_supervisor']);
    }

    public function test_an_interactive_run_can_choose_a_management_level(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            ...self::ACCOUNT,
        ])->expectsChoice('Role', 'supervisor', AddFacilityUser::levelChoices())->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertSame(ManagementLevel::Supervisor, $staff->management_level);
        $this->assertNull($staff->department);
    }

    public function test_an_unknown_role_creates_nothing(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--role' => 'radiologist',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_a_blood_bank_account_takes_the_blood_bank_role_and_no_department(): void
    {
        $bloodBank = Facility::factory()->bloodBank()->approved()->create();

        $this->artisan('facility:add-user', [
            '--facility' => $bloodBank->id,
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertSame($bloodBank->id, $staff->facility_id);
        $this->assertNull($staff->department);
        $this->assertTrue($staff->hasRole(RoleName::BloodBank));

        // Head unless told otherwise.
        $this->assertSame(HospitalRole::Head, $staff->hospital_role);
    }

    public function test_a_blood_bank_account_may_be_given_a_narrower_hospital_role(): void
    {
        $bloodBank = Facility::factory()->bloodBank()->approved()->create();

        $this->artisan('facility:add-user', [
            '--facility' => $bloodBank->id,
            '--hospital-role' => 'inventory_staff',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $this->assertSame(HospitalRole::InventoryStaff, User::where('email', 'jomar@redagos.test')->sole()->hospital_role);

        $this->artisan('facility:add-user', [
            '--facility' => $bloodBank->id,
            '--hospital-role' => 'nurse',
            ...self::ACCOUNT,
            '--email' => 'other@redagos.test',
        ])->assertFailed();
    }

    public function test_a_blood_bank_has_no_departments_to_be_posted_to(): void
    {
        $bloodBank = Facility::factory()->bloodBank()->approved()->create();

        $this->artisan('facility:add-user', [
            '--facility' => $bloodBank->id,
            '--role' => 'inventory_control_officer',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_primary_records_the_account_as_the_facility_contact(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--level' => 'admin',
            '--primary' => true,
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $this->assertSame(
            User::where('email', 'jomar@redagos.test')->sole()->id,
            $this->bloodCenter->fresh()->registration_contact_user_id
        );
    }

    public function test_the_account_is_verified_without_mailing_a_link(): void
    {
        Notification::fake();

        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--role' => 'inventory_control_officer',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        Notification::assertNothingSent();

        $this->postJson('/api/login', [
            'email' => 'jomar@redagos.test',
            'password' => 'Sup3rSecret',
            'role' => 'blood_center',
        ])->assertOk();
    }

    public function test_an_unknown_facility_creates_nothing(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id + 999,
            '--role' => 'inventory_control_officer',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_a_badge_number_is_unique_within_a_facility_but_not_across_them(): void
    {
        $other = Facility::factory()->approved()->create();

        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--role' => 'inventory_control_officer',
            '--employee-id' => '001',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        // The same badge at a different centre is a different person's badge.
        $this->artisan('facility:add-user', [
            '--facility' => $other->id,
            '--role' => 'inventory_control_officer',
            '--employee-id' => '001',
            ...[...self::ACCOUNT, '--email' => 'second@redagos.test'],
        ])->assertSuccessful();

        // The same badge at the same centre is a collision.
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--role' => 'inventory_control_officer',
            '--employee-id' => '001',
            ...[...self::ACCOUNT, '--email' => 'third@redagos.test'],
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'third@redagos.test']);
    }

    public function test_it_leaves_the_administrator_in_the_audit_trail(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--role' => 'inventory_control_officer',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.created',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_it_refuses_to_run_with_no_administrator_to_record(): void
    {
        $this->admin->roles()->detach();

        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--role' => 'inventory_control_officer',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }
}
