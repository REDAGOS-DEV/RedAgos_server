<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PromptsForAccountDetails;
use App\Console\Commands\Concerns\ResolvesActingAdministrator;
use App\Enums\Department;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\HospitalRole;
use App\Enums\ManagementLevel;
use App\Enums\StaffPrivilege;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Repository\AuthRepository;
use App\Repository\BloodCenterRepository;
use App\Repository\FacilityRepository;
use App\Service\AuditLogger;
use App\Support\AccountIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Add an account to a facility that already exists.
 *
 * Deliberately not routed through StaffService, which is the roster endpoint a
 * blood centre's Admin uses. That service resolves the facility from the
 * authenticated caller and grants RoleName::BloodCenter unconditionally, so it
 * can neither serve a facility whose first account does not exist yet — the
 * chicken-and-egg this command is here to break — nor a hospital blood bank.
 *
 * The access role is taken from the facility's own type instead, which is the
 * same answer FacilityTypeName gives the onboarding flow. A staff role is asked
 * for only where it means something: the matrix charters the roles of a blood
 * centre, and a blood bank has none of them, so a blood bank account is
 * authorised by its access role and its facility rather than by a post.
 *
 * Rules are stated here rather than read off StoreStaffRequest for that same
 * reason. That request scopes employee_id uniqueness to the *caller's* facility
 * and requires a staff role of every account without a management level, both
 * of which are correct for a blood centre roster and wrong for this.
 */
class AddFacilityUser extends Command
{
    use PromptsForAccountDetails, ResolvesActingAdministrator;

    protected $signature = 'facility:add-user
                            {--facility= : Id of the facility this account belongs to}
                            {--first-name= : Given name}
                            {--last-name= : Surname}
                            {--email= : Sign-in address}
                            {--phone= : Mobile number, optional}
                            {--username= : Defaults to one derived from the email}
                            {--position= : Job title, optional}
                            {--employee-id= : Badge number, unique within the facility}
                            {--role= : Staff role, e.g. medical_receptionist or lab_supervisor (blood centers only)}
                            {--hospital-role= : head, inventory_staff or requesting_staff (blood banks only; default head)}
                            {--level= : admin or supervisor (blood centers only); with --role, on top of the role}
                            {--privileges= : Comma-separated cap: read,write,update,delete (default: all four)}
                            {--primary : Also record this account as the facility contact}
                            {--password= : Leave unset to be prompted; an argument is visible in shell history}
                            {--admin= : Email of the administrator recorded in the audit trail}';

    protected $description = 'Add a staff account to an existing facility';

    public function __construct(
        private readonly AuthRepository $authRepository,
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly FacilityRepository $facilityRepository,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $facility = $this->resolveFacility();

        if ($facility === null) {
            return self::FAILURE;
        }

        $type = FacilityTypeName::tryFrom((string) $facility->facilityType?->name);

        if ($type === null) {
            $this->error($facility->name.' is filed under a facility type this application does not staff.');

            return self::FAILURE;
        }

        $admin = $this->resolveActingAdministrator();

        if ($admin === null) {
            return self::FAILURE;
        }

        $this->promptForLevel($type);

        $level = $this->resolveLevel($type);

        if ($level === false) {
            return self::FAILURE;
        }

        $role = $this->resolveRole($type, $level);

        if ($role === false) {
            return self::FAILURE;
        }

        $privileges = $this->resolvePrivileges();

        if ($privileges === false) {
            return self::FAILURE;
        }

        $hospitalRole = $this->resolveHospitalRole($type);

        if ($hospitalRole === false) {
            return self::FAILURE;
        }

        $attributes = $this->collectAttributes();

        if ($attributes === null) {
            return self::FAILURE;
        }

        $validator = Validator::make($attributes, [
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],
            'username' => ['nullable', 'string', 'max:150', 'unique:users,username'],
            'position' => ['nullable', 'string', 'max:100'],

            // users carries unique(facility_id, employee_id), so the rule is
            // scoped the same way: two facilities may both have a badge "001".
            'employee_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')->where('facility_id', $facility->id),
            ],

            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $staff = $this->createAccount($facility, $type, $attributes, $role, $level, $privileges, $hospitalRole);

        $this->auditLogger->record($admin, 'staff.created', $staff, [
            'facility_id' => $facility->id,
            'staff_role' => $role?->value,
            'department' => $role?->department()->value,
            'management_level' => $level?->value,
            'privileges' => $privileges,
            'source' => 'console:facility:add-user',
        ]);

        $this->report($facility, $type, $staff, $role, $level);

        return self::SUCCESS;
    }

    /**
     * Find the facility the account is being added to.
     *
     * Lists what is available on a miss: the id is the one field an operator
     * cannot reasonably be expected to know by heart.
     */
    private function resolveFacility(): ?Facility
    {
        $id = $this->optionOrAsk('facility', 'Facility id');

        $facility = ctype_digit($id)
            ? Facility::query()->with('facilityType')->find((int) $id)
            : null;

        if ($facility === null) {
            $this->error($id === '' ? 'A facility id is required.' : "No facility with id {$id}.");
            $this->listFacilities();

            return null;
        }

        if ($facility->status !== FacilityStatus::Approved) {
            // Not refused: the account is legitimate, and approval is a separate
            // gate that an administrator resolves on its own terms. Said plainly
            // so the sign-in failure afterwards is not a surprise.
            $this->warn($facility->name.' is '.$facility->status->value.'. Its staff cannot reach the portal until it is approved.');
        }

        return $facility;
    }

    /**
     * Print the facilities an account could be added to.
     */
    private function listFacilities(): void
    {
        $facilities = Facility::query()->with('facilityType')->orderBy('id')->get();

        if ($facilities->isEmpty()) {
            $this->line('No facilities exist yet. Create one with facility:create.');

            return;
        }

        $this->table(['Id', 'Name', 'Type', 'Status'], $facilities->map(fn (Facility $facility): array => [
            $facility->id,
            $facility->name,
            $facility->facilityType?->name,
            $facility->status->value,
        ])->all());
    }

    /**
     * Ask for a blood centre account's level when neither option was passed.
     *
     * The answer is a staff role or a management level, which are two options
     * rather than one field, so optionOrAsk cannot cover it. The answer is
     * written back onto the input so resolveLevel and resolveRole judge a
     * prompted run exactly as they would the equivalent flags. A
     * --no-interaction run is left alone and refused there by name.
     */
    private function promptForLevel(FacilityTypeName $type): void
    {
        if (! $type->acceptsDonorBookings()
            || ! $this->input->isInteractive()
            || $this->optionOrNull('level') !== null
            || $this->optionOrNull('role') !== null) {
            return;
        }

        $answer = $this->choice('Role', self::levelChoices());

        if (ManagementLevel::tryFrom($answer) !== null) {
            $this->input->setOption('level', $answer);
        } else {
            $this->input->setOption('role', $answer);
        }
    }

    /**
     * Every level a blood centre account can be given, keyed by the option value.
     *
     * Each role is prefixed with its department so the list reads as the
     * organisation chart does.
     *
     * @return array<string, string>
     */
    public static function levelChoices(): array
    {
        $choices = [];

        foreach (Department::cases() as $department) {
            foreach ($department->roles() as $role) {
                $choices[$role->value] = $department->label().' — '.$role->label();
            }
        }

        $choices[ManagementLevel::Admin->value] = 'Blood Center Admin (staff accounts and settings, no role)';
        $choices[ManagementLevel::Supervisor->value] = 'Supervisor (view-only monitoring, no role)';

        return $choices;
    }

    /**
     * Read --level, or false after reporting why it cannot stand.
     *
     * A hospital blood bank has no management level: its Head, a hospital
     * role, manages its staff.
     */
    private function resolveLevel(FacilityTypeName $type): ManagementLevel|false|null
    {
        $supplied = $this->optionOrNull('level');

        if ($supplied === null) {
            return null;
        }

        if (! $type->acceptsDonorBookings()) {
            $this->error('A hospital blood bank has no management level. Its Head manages its staff: use --hospital-role=head.');

            return false;
        }

        $level = ManagementLevel::tryFrom(Str::lower(trim($supplied)));

        if ($level === null) {
            $this->error('Unknown level. Use one of: '.implode(', ', ManagementLevel::values()).'.');

            return false;
        }

        return $level;
    }

    /**
     * Settle the staff role, or report why the combination cannot stand.
     *
     * Returns false rather than null on a refusal, because null is itself a
     * valid answer: an Admin or Supervisor with no post, and every blood bank
     * account, hold no staff role at all.
     */
    private function resolveRole(FacilityTypeName $type, ?ManagementLevel $level): StaffRole|false|null
    {
        $supplied = $this->optionOrNull('role');

        if (! $type->acceptsDonorBookings()) {
            if ($supplied !== null) {
                $this->error('A hospital blood bank has no staff roles. Drop --role, and use --hospital-role= instead.');

                return false;
            }

            return null;
        }

        if ($supplied === null) {
            if ($level !== null) {
                return null;
            }

            $this->error('Choose a role with --role=, or a management level with --level=admin or --level=supervisor.');
            $this->listRoles();

            return false;
        }

        $role = StaffRole::tryFrom(Str::lower($supplied));

        if ($role === null) {
            $this->error("Unknown role {$supplied}.");
            $this->listRoles();

            return false;
        }

        return $role;
    }

    /**
     * Read --privileges, or false after reporting an unknown one.
     *
     * Null when omitted, which the account reads as all four.
     *
     * @return array<int, string>|false|null
     */
    private function resolvePrivileges(): array|false|null
    {
        $supplied = $this->optionOrNull('privileges');

        if ($supplied === null) {
            return null;
        }

        $privileges = array_values(array_unique(array_filter(array_map(
            fn (string $privilege): string => Str::lower(trim($privilege)),
            explode(',', $supplied)
        ))));

        $unknown = array_diff($privileges, StaffPrivilege::values());

        if ($privileges === [] || $unknown !== []) {
            $this->error('Privileges are any of: '.implode(', ', StaffPrivilege::values()).'.');

            return false;
        }

        return $privileges;
    }

    /**
     * Print the roles an account can be given, grouped by department.
     */
    private function listRoles(): void
    {
        foreach (Department::cases() as $department) {
            $this->line($department->label().': '.implode(', ', array_map(
                fn (StaffRole $role): string => $role->value,
                $department->roles()
            )));
        }
    }

    /**
     * Gather the account fields, asking for whatever was not passed.
     *
     * Returns null when the two password entries disagree.
     *
     * @return array<string, mixed>|null
     */
    private function collectAttributes(): ?array
    {
        $attributes = [
            'first_name' => $this->optionOrAsk('first-name', 'First name'),
            'last_name' => $this->optionOrAsk('last-name', 'Last name'),
            'email' => $this->emailOrAsk('email', 'Email'),
            'phone' => $this->optionOrNull('phone'),
            'username' => $this->optionOrNull('username'),
            'position' => $this->optionOrNull('position'),
            'employee_id' => $this->optionOrNull('employee-id'),
        ];

        if ($attributes['phone'] !== null) {
            $attributes['phone'] = AccountIdentity::normalizePhilippinePhone($attributes['phone']);
        }

        $password = $this->passwordOrAsk();

        return $password === null ? null : [...$attributes, 'password' => $password];
    }

    /**
     * The role a hospital blood bank account holds: Head unless told otherwise.
     *
     * Hospital roles were added by the project owner on 2026-10-11; an account
     * without one reaches nothing in the hospital portal, and the operator at
     * the terminal is vouching for it as every hospital account once was.
     *
     * @return HospitalRole|false|null Null for a blood-centre account; false on a role this application does not know.
     */
    private function resolveHospitalRole(FacilityTypeName $type): HospitalRole|false|null
    {
        $typed = $this->option('hospital-role');

        if ($type !== FacilityTypeName::BloodBank) {
            if (filled($typed)) {
                $this->error('--hospital-role is for hospital blood banks only.');

                return false;
            }

            return null;
        }

        if (blank($typed)) {
            return HospitalRole::Head;
        }

        $role = HospitalRole::tryFrom(Str::lower(trim((string) $typed)));

        if ($role === null) {
            $this->error('Unknown hospital role. Use one of: '.implode(', ', HospitalRole::values()).'.');

            return false;
        }

        return $role;
    }

    /**
     * Write the account, its role, and the facility contact if asked for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createAccount(
        Facility $facility,
        FacilityTypeName $type,
        array $attributes,
        ?StaffRole $role,
        ?ManagementLevel $level,
        ?array $privileges = null,
        ?HospitalRole $hospitalRole = null
    ): User {
        return DB::transaction(function () use ($facility, $type, $attributes, $role, $level, $privileges, $hospitalRole): User {
            $staff = $this->bloodCenterRepository->createStaffUser([
                'uuid' => (string) Str::uuid(),
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'],
                'username' => $attributes['username'] ?? $this->deriveUsername($attributes['email']),
                // The hashed cast turns this into a hash on save; passing an
                // already-hashed value here would be hashed a second time.
                'password' => $attributes['password'],
                'employee_id' => $attributes['employee_id'],
                'position' => $attributes['position'],
            ], $facility, $role, $level, privileges: $privileges);

            // Verified on creation, with no link mailed: the operator at the
            // terminal is vouching for the account in person. This is the same
            // state the emailed link leaves behind, so the account is active
            // rather than merely verified.
            $this->authRepository->markVerified($staff);

            $this->facilityRepository->attachRole($staff, $type->role());

            if ($hospitalRole !== null) {
                $staff->hospital_role = $hospitalRole;
                $staff->save();
            }

            if ($this->option('primary')) {
                $this->facilityRepository->setPrimaryAccount($facility, $staff);
            }

            return $staff;
        });
    }

    /**
     * Derive a username the unique index will accept.
     *
     * The suffix is random, so a collision is remote — but retrying is cheaper
     * than failing an otherwise valid run, and the index stays the real
     * guarantee either way.
     */
    private function deriveUsername(string $email): string
    {
        $candidate = AccountIdentity::buildUsername($email);

        for ($attempt = 0; $attempt < 5 && $this->facilityRepository->usernameExists($candidate); $attempt++) {
            $candidate = AccountIdentity::buildUsername($email);
        }

        return $candidate;
    }

    private function report(
        Facility $facility,
        FacilityTypeName $type,
        User $staff,
        ?StaffRole $role,
        ?ManagementLevel $level
    ): void {
        $post = $role === null ? null : $role->label().' ('.$role->department()->label().')';

        $this->info(trim($staff->first_name.' '.$staff->last_name).' added to '.$facility->name.'.');

        $this->table(['Field', 'Value'], [
            ['Facility', $facility->name.' ('.$type->label().')'],
            ['Email', $staff->email],
            ['Username', $staff->username],
            ['Level', match (true) {
                $level !== null && $post !== null => $level->label().' — '.$post,
                $level !== null => $level->label(),
                default => $post ?? 'Staff',
            }],
            ['Facility contact', $this->option('primary') ? 'Yes' : 'No'],
            ['Can sign in', 'Yes'],
        ]);
    }
}
