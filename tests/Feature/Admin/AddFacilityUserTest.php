<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Staffing a facility that has no account to staff it with.
 *
 * The roster endpoint resolves the facility from its caller, so it cannot serve
 * a facility whose first account does not exist — the seeded blood centres are
 * exactly that case. These tests pin the two things the command decides on its
 * own as a result: which role it grants, and whether a department means
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

    public function test_it_adds_a_departmental_account_to_a_blood_center(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--department' => 'inventory',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertSame($this->bloodCenter->id, $staff->facility_id);
        $this->assertSame(Department::Inventory, $staff->department);
        $this->assertFalse($staff->is_supervisor);
        $this->assertTrue($staff->hasRole(RoleName::BloodCenter));
        $this->assertSame(AccountStatus::PendingVerification, $staff->account_status);
        $this->assertNotNull($staff->username);
    }

    public function test_supervisor_grants_the_management_level_without_a_posting(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--supervisor' => true,
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $staff = User::where('email', 'jomar@redagos.test')->sole();

        $this->assertTrue($staff->is_supervisor);
        $this->assertNull($staff->department);
    }

    public function test_a_blood_center_account_must_be_posted_or_promoted(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_an_unknown_department_creates_nothing(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--department' => 'radiology',
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
    }

    public function test_a_blood_bank_has_no_departments_to_be_posted_to(): void
    {
        $bloodBank = Facility::factory()->bloodBank()->approved()->create();

        $this->artisan('facility:add-user', [
            '--facility' => $bloodBank->id,
            '--department' => 'inventory',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_primary_records_the_account_as_the_facility_contact(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--supervisor' => true,
            '--primary' => true,
            ...self::ACCOUNT,
        ])->assertSuccessful();

        $this->assertSame(
            User::where('email', 'jomar@redagos.test')->sole()->id,
            $this->bloodCenter->fresh()->registration_contact_user_id
        );
    }

    public function test_verified_produces_an_account_that_can_sign_in_at_once(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--department' => 'inventory',
            '--verified' => true,
            ...self::ACCOUNT,
        ])->assertSuccessful();

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
            '--department' => 'inventory',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }

    public function test_a_badge_number_is_unique_within_a_facility_but_not_across_them(): void
    {
        $other = Facility::factory()->approved()->create();

        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--department' => 'inventory',
            '--employee-id' => '001',
            ...self::ACCOUNT,
        ])->assertSuccessful();

        // The same badge at a different centre is a different person's badge.
        $this->artisan('facility:add-user', [
            '--facility' => $other->id,
            '--department' => 'inventory',
            '--employee-id' => '001',
            ...[...self::ACCOUNT, '--email' => 'second@redagos.test'],
        ])->assertSuccessful();

        // The same badge at the same centre is a collision.
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--department' => 'inventory',
            '--employee-id' => '001',
            ...[...self::ACCOUNT, '--email' => 'third@redagos.test'],
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'third@redagos.test']);
    }

    public function test_it_leaves_the_administrator_in_the_audit_trail(): void
    {
        $this->artisan('facility:add-user', [
            '--facility' => $this->bloodCenter->id,
            '--department' => 'inventory',
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
            '--department' => 'inventory',
            ...self::ACCOUNT,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'jomar@redagos.test']);
    }
}
