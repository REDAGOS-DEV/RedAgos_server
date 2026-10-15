<?php

namespace Database\Seeders;

use App\Enums\Department;
use App\Enums\FacilityTypeName;
use App\Enums\ManagementLevel;
use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Repository\AuthRepository;
use App\Repository\BloodCenterRepository;
use App\Repository\FacilityRepository;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One sample account for every post at a blood centre, organised by department.
 *
 * DEMO DATA, LOCAL ONLY. Not called from DatabaseSeeder: run it on purpose with
 * `php artisan db:seed --class=BloodCenterStaffSeeder`. It creates verified,
 * active accounts that all share one known password, so it refuses to run
 * anywhere but a local or testing environment — there is no override.
 *
 * The roster is the organisation chart: the Blood Center Admin and the
 * view-only Supervisor above it, then each department's head (the role that
 * approves its correction requests, Department::correctionApprover()) first,
 * then the rest. Heads are not flagged in the data; they are read off the
 * enum, so they cannot drift from it.
 *
 * Seeds Sub-National Blood Center only. The Admin is created first so it holds
 * the lowest id, which DemoInventorySeeder attributes its records to, and
 * becomes the facility contact if none is set. Re-running is a no-op:
 * accounts are keyed by email, and an existing one is left exactly as it is,
 * password included.
 */
class BloodCenterStaffSeeder extends Seeder
{
    use WithoutModelEvents;

    public const FACILITY = 'Sub-National Blood Center';

    public const PASSWORD = 'Password123';

    private const DOMAIN = 'redagos.test';

    /**
     * The two management accounts, Admin first.
     *
     * Teresa Aquino keeps the address, username and number she had when the
     * one supervisor account was the Center Admin, so a database seeded then
     * needs only her level changed (the migration made her an Admin).
     *
     * @var array<int, array{level: ManagementLevel, first: string, last: string, employee_id: string, email: string, username: string, position: string}>
     */
    private const MANAGEMENT = [
        [
            'level' => ManagementLevel::Admin,
            'first' => 'Eduardo', 'last' => 'Ramos', 'employee_id' => 'ADM-001',
            'email' => 'center.admin', 'username' => 'snbc-center-admin', 'position' => 'Blood Center Admin',
        ],
        [
            'level' => ManagementLevel::Supervisor,
            'first' => 'Teresa', 'last' => 'Aquino', 'employee_id' => 'SUP-001',
            'email' => 'supervisor', 'username' => 'snbc-supervisor', 'position' => 'Blood Center Supervisor',
        ],
    ];

    /**
     * Employee-number prefix for each department.
     *
     * @var array<string, string>
     */
    private const PREFIX = [
        'collection' => 'COL',
        'processing' => 'PRO',
        'testing' => 'TST',
        'issuance' => 'ISS',
        'billing' => 'BIL',
    ];

    /**
     * Who holds each post, by department and then role: [first name, last name].
     *
     * Each department lists its head first.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const ROSTER = [
        'collection' => [
            'screening_physician' => ['Ramon', 'Villanueva'],
            'phlebotomist' => ['Liza', 'Bautista'],
            'apheresis_specialist' => ['Marvin', 'Castillo'],
            'medical_receptionist' => ['Grace', 'Domingo'],
        ],
        'processing' => [
            'component_technologist' => ['Arnel', 'Navarro'],
            'processing_assistant' => ['Joy', 'Salazar'],
        ],
        'testing' => [
            'lab_supervisor' => ['Dennis', 'Ocampo'],
            'serology_technologist' => ['Kristine', 'Lim'],
        ],
        'issuance' => [
            'inventory_control_officer' => ['Rowena', 'Tan'],
            'dispatch_coordinator' => ['Jerome', 'Flores'],
            'it_data_clerk' => ['Paolo', 'Rivera'],
        ],
        'billing' => [
            'billing_supervisor' => ['Carmela', 'Reyes'],
            'billing_clerk' => ['Andrea', 'Mendoza'],
        ],
    ];

    public function __construct(
        private readonly BloodCenterRepository $bloodCenters,
        private readonly AuthRepository $auth,
        private readonly FacilityRepository $facilities
    ) {}

    /**
     * Every account the seeder creates, in the order it creates them.
     *
     * The one list the seeder, its test and the README all read, so the three
     * cannot disagree. The Admin comes first, then the Supervisor.
     *
     * @return array<int, array{department: string, role: StaffRole|null, level: ManagementLevel|null, first_name: string, last_name: string, employee_id: string, email: string, username: string, position: string, head: bool}>
     */
    public static function accounts(): array
    {
        $accounts = [];

        foreach (self::MANAGEMENT as $manager) {
            $accounts[] = [
                'department' => 'Management',
                'role' => null,
                'level' => $manager['level'],
                'first_name' => $manager['first'],
                'last_name' => $manager['last'],
                'employee_id' => $manager['employee_id'],
                'email' => $manager['email'].'@'.self::DOMAIN,
                'username' => $manager['username'],
                'position' => $manager['position'],
                'head' => false,
            ];
        }

        foreach (self::ROSTER as $department => $posts) {
            $sequence = 0;

            foreach ($posts as $roleValue => [$first, $last]) {
                $role = StaffRole::from($roleValue);
                $sequence++;

                $accounts[] = [
                    'department' => Department::from($department)->label(),
                    'role' => $role,
                    'level' => null,
                    'first_name' => $first,
                    'last_name' => $last,
                    'employee_id' => sprintf('%s-%03d', self::PREFIX[$department], $sequence),
                    'email' => str_replace('_', '.', $roleValue).'@'.self::DOMAIN,
                    'username' => 'snbc-'.str_replace('_', '-', $roleValue),
                    'position' => $role->label(),
                    'head' => Department::from($department)->correctionApprover() === $role,
                ];
            }
        }

        return $accounts;
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error(
                'Refusing to seed sample staff in the ['.app()->environment().'] environment: '
                .'it creates verified accounts that share one known password. Local and testing only.'
            );

            return;
        }

        $facility = Facility::query()
            ->where('name', self::FACILITY)
            ->whereHas('facilityType', fn ($query) => $query->where('name', FacilityTypeName::BloodCenter->value))
            ->first();

        if ($facility === null) {
            $this->command?->warn(self::FACILITY.' does not exist. Run FacilitySeeder first; skipping sample staff.');

            return;
        }

        $rows = DB::transaction(function () use ($facility): array {
            $rows = [];

            foreach (self::accounts() as $account) {
                [$user, $outcome] = $this->seedAccount($facility, $account);

                // The Admin is first, so by the time anyone else exists the
                // facility has its contact.
                if ($account['level'] === ManagementLevel::Admin && $user !== null && $facility->registration_contact_user_id === null) {
                    $this->facilities->setPrimaryAccount($facility, $user);
                }

                $rows[] = [
                    $account['department'],
                    $account['level']?->label() ?? $account['role']?->label(),
                    $account['head'] ? 'yes' : '',
                    $account['email'],
                    $account['username'],
                    $account['employee_id'],
                    $outcome,
                ];
            }

            return $rows;
        });

        $this->command?->table(['Department', 'Role', 'Head', 'Email', 'Username', 'Employee ID', 'Result'], $rows);
        $this->command?->info('Password for every account: '.self::PASSWORD);
    }

    /**
     * Create one account unless it, or something that would collide with it, exists.
     *
     * @param  array<string, mixed>  $account
     * @return array{0: User|null, 1: string} The user, and what happened.
     */
    private function seedAccount(Facility $facility, array $account): array
    {
        $existing = User::withTrashed()->where('email', $account['email'])->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $this->command?->warn("{$account['email']} was deleted; not restored.");

                return [null, 'deleted'];
            }

            return [$existing, 'exists'];
        }

        // The username and the employee number are unique; refuse a collision
        // with someone else's account rather than failing the whole run.
        if (User::withTrashed()->where('username', $account['username'])->exists()
            || User::withTrashed()->where('facility_id', $facility->id)->where('employee_id', $account['employee_id'])->exists()) {
            $this->command?->warn("{$account['email']} skipped: its username or employee number is already in use.");

            return [null, 'skipped'];
        }

        /** @var StaffRole|null $role */
        $role = $account['role'];

        $user = $this->bloodCenters->createStaffUser(
            [
                'uuid' => (string) Str::uuid(),
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'email' => $account['email'],
                'username' => $account['username'],
                // The hashed cast hashes this on save.
                'password' => self::PASSWORD,
                'employee_id' => $account['employee_id'],
                'position' => $account['position'],
            ],
            $facility,
            $role,
            $account['level'],
            // Passed explicitly: this seeder runs without model events, and
            // the saving hook that derives it from the role would not fire.
            department: $role?->department()
        );

        $this->auth->markVerified($user);
        $this->facilities->attachRole($user, RoleName::BloodCenter);

        return [$user, 'created'];
    }
}
