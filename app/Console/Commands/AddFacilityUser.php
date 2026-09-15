<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PromptsForAccountDetails;
use App\Console\Commands\Concerns\ResolvesActingAdministrator;
use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Models\Facility;
use App\Models\User;
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
 * blood centre supervisor uses. That service resolves the facility from the
 * authenticated caller and grants RoleName::BloodCenter unconditionally, so it
 * can neither serve a facility whose first account does not exist yet — the
 * chicken-and-egg this command is here to break — nor a hospital blood bank.
 *
 * The role is taken from the facility's own type instead, which is the same
 * answer FacilityTypeName gives the onboarding flow. Departments are asked for
 * only where they mean something: the matrix charters the four departments of a
 * blood centre, and a blood bank has none of them, so a blood bank account is
 * authorised by its role and its facility rather than by a posting.
 *
 * Rules are stated here rather than read off StoreStaffRequest for that same
 * reason. That request scopes employee_id uniqueness to the *caller's* facility
 * and requires a department of every non-supervisor, both of which are correct
 * for a blood centre roster and wrong for this.
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
                            {--department= : collection, laboratory, inventory or billing (blood centers only)}
                            {--supervisor : Grant the management level instead of a department}
                            {--primary : Also record this account as the facility contact}
                            {--password= : Leave unset to be prompted; an argument is visible in shell history}
                            {--admin= : Email of the administrator recorded in the audit trail}
                            {--verified : Mark the account verified instead of waiting on the emailed link}';

    protected $description = 'Add a staff account to an existing facility';

    public function __construct(
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

        $isSupervisor = (bool) $this->option('supervisor');
        $department = $this->resolveDepartment($type, $isSupervisor);

        if ($department === false) {
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

        $staff = $this->createAccount($facility, $type, $attributes, $department, $isSupervisor);

        if ($this->option('verified')) {
            $staff->markEmailAsVerified();
        } else {
            // After the commit, so a rolled-back creation never mails a live link.
            $staff->sendEmailVerificationNotification();
        }

        $this->auditLogger->record($admin, 'staff.created', $staff, [
            'facility_id' => $facility->id,
            'department' => $department?->value,
            'is_supervisor' => $isSupervisor,
            'source' => 'console:facility:add-user',
        ]);

        $this->report($facility, $type, $staff, $department, $isSupervisor);

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
     * Settle the department, or report why the combination cannot stand.
     *
     * Returns false rather than null on a refusal, because null is itself a
     * valid answer: a supervisor and every blood bank account hold no
     * department at all.
     */
    private function resolveDepartment(FacilityTypeName $type, bool $isSupervisor): Department|false|null
    {
        $supplied = $this->optionOrNull('department');

        if (! $type->acceptsDonorBookings()) {
            if ($supplied !== null) {
                $this->error('A hospital blood bank has no departments. Drop --department, and add --supervisor if the account is management.');

                return false;
            }

            return null;
        }

        if ($supplied === null) {
            if ($isSupervisor) {
                return null;
            }

            $this->error('Choose a department with --department=, or grant the management level with --supervisor.');
            $this->line('Departments: '.implode(', ', Department::values()));

            return false;
        }

        $department = Department::tryFrom(Str::lower($supplied));

        if ($department === null) {
            $this->error("Unknown department {$supplied}.");
            $this->line('Departments: '.implode(', ', Department::values()));

            return false;
        }

        return $department;
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
     * Write the account, its role, and the facility contact if asked for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createAccount(
        Facility $facility,
        FacilityTypeName $type,
        array $attributes,
        ?Department $department,
        bool $isSupervisor
    ): User {
        return DB::transaction(function () use ($facility, $type, $attributes, $department, $isSupervisor): User {
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
                // Sign-in is refused until the address is verified, which is
                // what --verified answers. An operator creating the account does
                // not make the address any more proven than self-registration
                // did, so the default stands where the API puts it.
                'account_status' => AccountStatus::PendingVerification,
                'employee_id' => $attributes['employee_id'],
                'position' => $attributes['position'],
            ], $facility, $department, $isSupervisor);

            $this->facilityRepository->attachRole($staff, $type->role());

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
        ?Department $department,
        bool $isSupervisor
    ): void {
        $this->info(trim($staff->first_name.' '.$staff->last_name).' added to '.$facility->name.'.');

        $this->table(['Field', 'Value'], [
            ['Facility', $facility->name.' ('.$type->label().')'],
            ['Email', $staff->email],
            ['Username', $staff->username],
            ['Level', $isSupervisor ? 'Supervisor' : ($department?->label() ?? 'Staff')],
            ['Facility contact', $this->option('primary') ? 'Yes' : 'No'],
            ['Can sign in', $this->option('verified')
                ? 'Yes'
                : 'Not until the emailed verification link is opened'],
        ]);
    }
}
