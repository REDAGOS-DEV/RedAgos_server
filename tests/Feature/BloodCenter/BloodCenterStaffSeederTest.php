<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use Database\Seeders\BloodCenterStaffSeeder;
use Database\Seeders\FacilitySeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BloodCenterStaffSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function seedStaff(): void
    {
        $this->seed(FacilitySeeder::class);
        $this->seed(BloodCenterStaffSeeder::class);
    }

    private function facility(): Facility
    {
        return Facility::query()->where('name', BloodCenterStaffSeeder::FACILITY)->firstOrFail();
    }

    /**
     * @return Collection<int, User>
     */
    private function staff(): Collection
    {
        return User::query()->where('facility_id', $this->facility()->id)->orderBy('id')->get();
    }

    public function test_it_seeds_a_center_admin_and_one_account_per_role(): void
    {
        $this->seedStaff();

        $staff = $this->staff();

        $this->assertCount(count(StaffRole::cases()) + 1, $staff);
        $this->assertSame(14, $staff->count());

        $admins = $staff->where('is_supervisor', true);
        $this->assertCount(1, $admins);
        $this->assertNull($admins->first()->staff_role);
        $this->assertNull($admins->first()->department);

        // A role added to the enum without a sample account fails here.
        foreach (StaffRole::cases() as $role) {
            $holders = $staff->where('staff_role', $role);

            $this->assertCount(1, $holders, "{$role->value} must have exactly one sample account.");
            $this->assertSame($role->department(), $holders->first()->department, "{$role->value} must sit in its own department.");
            $this->assertFalse((bool) $holders->first()->is_supervisor);
        }
    }

    public function test_the_center_admin_is_created_first_and_is_the_facility_contact(): void
    {
        $this->seedStaff();

        $first = $this->staff()->first();

        $this->assertSame('supervisor@redagos.test', $first->email);
        $this->assertSame($first->id, $this->facility()->registration_contact_user_id);
    }

    public function test_an_existing_facility_contact_is_not_replaced(): void
    {
        $this->seed(FacilitySeeder::class);

        $contact = User::factory()->bloodCenterStaff($this->facility())->create();
        $facility = $this->facility();
        $facility->registration_contact_user_id = $contact->id;
        $facility->save();

        $this->seed(BloodCenterStaffSeeder::class);

        $this->assertSame($contact->id, $this->facility()->registration_contact_user_id);
    }

    public function test_every_departments_head_comes_first_and_is_its_correction_approver(): void
    {
        $this->seedStaff();

        $accounts = collect(BloodCenterStaffSeeder::accounts())->skip(1)->groupBy('department');

        foreach (Department::cases() as $department) {
            $rows = $accounts[$department->label()];
            $heads = $rows->where('head', true);

            $this->assertCount(1, $heads, "{$department->value} must have exactly one head.");
            $this->assertSame($department->correctionApprover(), $heads->first()['role']);
            $this->assertSame($heads->first(), $rows->first(), "{$department->value}'s head is listed first.");
        }
    }

    public function test_the_identities_follow_one_convention(): void
    {
        $this->seedStaff();

        $accounts = collect(BloodCenterStaffSeeder::accounts());

        $this->assertSame($accounts->count(), $accounts->pluck('email')->unique()->count());
        $this->assertSame($accounts->count(), $accounts->pluck('username')->unique()->count());
        $this->assertSame($accounts->count(), $accounts->pluck('employee_id')->unique()->count());

        $this->assertSame('phlebotomist@redagos.test', $accounts->firstWhere('role', StaffRole::Phlebotomist)['email']);
        $this->assertSame('lab.supervisor@redagos.test', $accounts->firstWhere('role', StaffRole::LabSupervisor)['email']);
        $this->assertSame('snbc-it-data-clerk', $accounts->firstWhere('role', StaffRole::ItDataClerk)['username']);
        $this->assertSame('BIL-001', $accounts->firstWhere('role', StaffRole::BillingSupervisor)['employee_id']);
        $this->assertSame('SUP-001', $accounts->first()['employee_id']);

        foreach ($this->staff() as $user) {
            $this->assertNotNull(User::query()->where('email', $user->email)->first());
        }
    }

    public function test_every_account_is_verified_active_and_holds_the_blood_center_role(): void
    {
        $this->seedStaff();

        foreach ($this->staff() as $user) {
            $this->assertNotNull($user->email_verified_at, "{$user->email} must be verified.");
            $this->assertSame(AccountStatus::Active, $user->account_status);
            $this->assertNotNull($user->activated_at);
            $this->assertTrue($user->hasRole(RoleName::BloodCenter));
            $this->assertTrue(Hash::check(BloodCenterStaffSeeder::PASSWORD, $user->password));
        }
    }

    public function test_re_running_creates_nothing_and_leaves_passwords_alone(): void
    {
        $this->seedStaff();

        $user = User::query()->where('email', 'phlebotomist@redagos.test')->firstOrFail();
        $user->password = 'Changed-Pass9';
        $user->save();

        $this->seed(BloodCenterStaffSeeder::class);

        $this->assertSame(14, $this->staff()->count());
        $this->assertTrue(Hash::check('Changed-Pass9', $user->fresh()->password));
    }

    public function test_a_deleted_account_is_not_restored(): void
    {
        $this->seedStaff();

        User::query()->where('email', 'billing.clerk@redagos.test')->firstOrFail()->delete();

        $this->seed(BloodCenterStaffSeeder::class);

        $this->assertNull(User::query()->where('email', 'billing.clerk@redagos.test')->first());
        $this->assertSame(1, User::onlyTrashed()->where('email', 'billing.clerk@redagos.test')->count());
    }

    public function test_it_seeds_nothing_when_the_facility_does_not_exist(): void
    {
        $this->seed(BloodCenterStaffSeeder::class);

        $this->assertSame(0, User::query()->where('email', 'like', '%@redagos.test')->count());
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        $this->seed(FacilitySeeder::class);

        foreach (['production', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            // Run directly rather than through db:seed. The command asks for
            // confirmation in production and `--force` skips it, so the
            // seeder's own refusal is what must hold.
            $this->app->make(BloodCenterStaffSeeder::class)->run();

            $this->assertSame(0, $this->staff()->count(), "No account may be created in [{$environment}].");
        }
    }

    public function test_the_seeded_accounts_can_sign_in_to_the_blood_center_portal(): void
    {
        $this->seedStaff();

        // Two only: the login route is throttled to five a minute.
        foreach (['supervisor@redagos.test', 'billing.supervisor@redagos.test'] as $email) {
            $this->postJson('/api/login', [
                'email' => $email,
                'password' => BloodCenterStaffSeeder::PASSWORD,
                'role' => 'blood-center',
            ])->assertOk();
        }
    }

    public function test_each_head_holds_the_approval_ability_of_their_department(): void
    {
        $this->seedStaff();

        foreach (BloodCenterStaffSeeder::accounts() as $account) {
            if ($account['role'] === null) {
                continue;
            }

            $user = User::query()->where('email', $account['email'])->firstOrFail();

            $this->assertSame(
                $account['head'],
                in_array('corrections.approve', $user->abilities(), true),
                "{$account['email']} head status must match corrections.approve."
            );
        }
    }
}
