<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\ManagementLevel;
use App\Enums\RoleName;
use App\Enums\StaffPrivilege;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Repository\StaffRepository;
use App\Support\AccountIdentity;
use App\Support\DepartmentPermissions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Roster management for one blood centre, performed by its Blood Center Admin.
 *
 * Every method resolves the facility from the authenticated caller and scopes
 * its queries to it. can:staff.manage says the caller may manage staff; it says
 * nothing about whose, so the boundary is enforced here rather than by the
 * ability.
 */
class StaffService
{
    public function __construct(
        private readonly StaffRepository $staffRepository,
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Page the caller's own facility roster.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->staffRepository
            ->paginateForFacility($this->requireFacility($actor)->id, $filters, $perPage)
            ->through(fn (User $staff): array => $this->format($staff));
    }

    /**
     * Show one staff account from the caller's facility.
     *
     * @return array<string, mixed>
     */
    public function show(User $actor, string $uuid): array
    {
        return $this->format($this->findOrFail($actor, $uuid, withTrashed: true));
    }

    /**
     * Create a colleague at the caller's facility.
     *
     * The account is created unverified and mailed a verification link, exactly
     * as self-registration leaves an applicant. That is deliberate: the
     * operational gate already refuses an unverified account with
     * email_unverified while still letting it sign in to change its own
     * password, so no additional onboarding gate is needed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $actor, array $payload): array
    {
        $facility = $this->requireFacility($actor);
        $level = isset($payload['management_level']) ? ManagementLevel::from($payload['management_level']) : null;
        $role = isset($payload['staff_role']) ? StaffRole::from($payload['staff_role']) : null;
        $additional = $this->rolesFrom($payload['additional_staff_roles'] ?? []);

        // Several roles but no main one: the first is the main one.
        if ($role === null && $additional !== []) {
            $role = array_shift($additional);
        }

        $additional = array_values(array_filter($additional, fn (StaffRole $r): bool => $r !== $role));

        // Beside a predefined role or in place of one, since 2026-10-17.
        $customRole = filled($payload['custom_role'] ?? null) ? trim((string) $payload['custom_role']) : null;
        $department = $role?->department()
            ?? (isset($payload['department']) ? Department::from($payload['department']) : null);
        $privileges = isset($payload['staff_privileges'])
            ? array_values(array_unique($payload['staff_privileges']))
            : null;

        $this->guardRoleAssigned($role, $customRole, $department, $level !== null);
        $this->guardOneDepartment($additional, $department);

        try {
            $staff = DB::transaction(function () use ($facility, $payload, $role, $additional, $level, $department, $customRole, $privileges): User {
                $created = $this->bloodCenterRepository->createStaffUser([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => trim($payload['first_name']),
                    'last_name' => trim($payload['last_name']),
                    // Already lowercased and normalised to E.164 by the request.
                    'email' => $payload['email'],
                    'phone' => $payload['phone'] ?? null,
                    'username' => AccountIdentity::buildUsername($payload['email']),
                    'password' => Hash::make($payload['password']),
                    'account_status' => AccountStatus::PendingVerification,
                    'employee_id' => $payload['employee_id'] ?? null,
                    'credentials' => filled($payload['credentials'] ?? null) ? trim((string) $payload['credentials']) : null,
                ], $facility, $role, $level, $department, $customRole, $privileges, $additional);

                // The facility is necessarily approved — staff.manage sits behind
                // facility.operational — so the role is granted immediately.
                // syncWithoutDetaching, never sync, which would strip roles.
                $this->bloodCenterRepository->attachRole($created, RoleName::BloodCenter->value);

                return $created;
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // Sent after the commit so a rolled-back creation never mails a live link.
        $staff->sendEmailVerificationNotification();

        $this->auditLogger->record($actor, 'staff.created', $staff, [
            'facility_id' => $facility->id,
            'staff_role' => $role?->value,
            'additional_staff_roles' => array_map(fn (StaffRole $r): string => $r->value, $additional),
            'custom_role' => $customRole,
            'department' => $department?->value,
            'privileges' => $privileges,
            'management_level' => $level?->value,
        ]);

        return [
            'message' => $staff->first_name.' has been added to your team.',
            'data' => $this->format($staff),
        ];
    }

    /**
     * Update a colleague's role, management level, posting or account status.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $actor, string $uuid, array $payload): array
    {
        $facility = $this->requireFacility($actor);
        $staff = $this->findOrFail($actor, $uuid);

        $before = [
            'roles' => $this->roleValues($staff),
            'role_label' => $staff->roleLabel(),
            'department' => $staff->department?->value,
            'privileges' => $this->privilegeValues($staff),
            'management_level' => $staff->management_level?->value,
            'account_status' => $staff->account_status?->value,
        ];

        try {
            $staff = DB::transaction(function () use ($facility, $staff, $payload): User {
                // Serialise on the facility row before touching anything that
                // could reduce the Admin count. Two concurrent demotions would
                // otherwise both read "two Admins" and both proceed.
                $this->bloodCenterRepository->lockFacility($facility->id);

                $this->applyUpdate($staff, $payload);
                $staff->save();

                $this->guardAdminFloor($facility->id);

                return $staff;
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // An account that can no longer sign in must not keep a live token:
        // account_status is checked at login only, so an existing token would
        // otherwise outlive the suspension.
        if (! $staff->account_status->canAuthenticate()) {
            $staff->tokens()->delete();
        }

        $this->recordUpdate($actor, $staff, $before);

        return [
            'message' => $staff->first_name."'s account has been updated.",
            'data' => $this->format($staff),
        ];
    }

    /**
     * Soft-delete a colleague and end their session immediately.
     *
     * @return array<string, mixed>
     */
    public function delete(User $actor, string $uuid): array
    {
        $facility = $this->requireFacility($actor);
        $staff = $this->findOrFail($actor, $uuid);

        DB::transaction(function () use ($facility, $staff): void {
            $this->bloodCenterRepository->lockFacility($facility->id);

            $staff->delete();

            $this->guardAdminFloor($facility->id);
        });

        $staff->tokens()->delete();

        $this->auditLogger->record($actor, 'staff.deleted', $staff, [
            'facility_id' => $facility->id,
        ]);

        return ['message' => $staff->first_name."'s account has been removed."];
    }

    /**
     * Restore a soft-deleted colleague.
     *
     * @return array<string, mixed>
     */
    public function restore(User $actor, string $uuid): array
    {
        $facility = $this->requireFacility($actor);
        $staff = $this->findOrFail($actor, $uuid, withTrashed: true);

        if (! $staff->trashed()) {
            throw $this->refuse(409, 'staff_not_deleted', 'This account is already active.');
        }

        $staff->restore();

        $this->auditLogger->record($actor, 'staff.restored', $staff, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => $staff->first_name."'s account has been restored.",
            'data' => $this->format($staff),
        ];
    }

    /**
     * Apply the mutable fields of an update to the model.
     *
     * Each field is written only when its key is present, so a partial update
     * that omits a role leaves the existing posting alone rather than clearing
     * it. The department is not written here: User's saving hook derives it
     * from the role.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyUpdate(User $staff, array $payload): void
    {
        if (array_key_exists('first_name', $payload)) {
            $staff->first_name = trim((string) $payload['first_name']);
        }

        if (array_key_exists('last_name', $payload)) {
            $staff->last_name = trim((string) $payload['last_name']);
        }

        if (array_key_exists('phone', $payload)) {
            $staff->phone = $payload['phone'];
        }

        if (array_key_exists('employee_id', $payload)) {
            $staff->employee_id = $payload['employee_id'];
        }

        if (array_key_exists('credentials', $payload)) {
            $staff->credentials = filled($payload['credentials']) ? trim((string) $payload['credentials']) : null;
        }

        if (array_key_exists('staff_role', $payload)) {
            $staff->staff_role = $payload['staff_role'] === null
                ? null
                : StaffRole::from($payload['staff_role']);
        }

        if (array_key_exists('additional_staff_roles', $payload)) {
            $staff->additional_staff_roles = array_map(
                fn (StaffRole $role): string => $role->value,
                $this->rolesFrom($payload['additional_staff_roles'] ?? [])
            );
        }

        if (array_key_exists('custom_role', $payload)) {
            $staff->custom_role = blank($payload['custom_role']) ? null : trim((string) $payload['custom_role']);
        } elseif (array_key_exists('staff_role', $payload) && $staff->staff_role !== null) {
            // A caller that sets the main role and says nothing of a custom
            // one is replacing it, as before several roles were possible.
            $staff->custom_role = null;
        }

        // Several roles but no main one: the first is the main one.
        $additional = $this->rolesFrom($staff->additional_staff_roles ?? []);

        if ($staff->staff_role === null && $additional !== []) {
            $staff->staff_role = array_shift($additional);
        }

        $additional = array_values(array_filter($additional, fn (StaffRole $r): bool => $r !== $staff->staff_role));
        $staff->additional_staff_roles = array_map(fn (StaffRole $r): string => $r->value, $additional) ?: null;

        if (array_key_exists('department', $payload)) {
            $staff->department = $payload['department'] === null ? null : Department::from($payload['department']);
        }

        if (array_key_exists('staff_privileges', $payload)) {
            $staff->staff_privileges = array_values(array_unique($payload['staff_privileges']));
        }

        if (array_key_exists('management_level', $payload)) {
            $staff->management_level = $payload['management_level'] === null
                ? null
                : ManagementLevel::from($payload['management_level']);
        }

        if (array_key_exists('account_status', $payload)) {
            $staff->account_status = AccountStatus::from($payload['account_status']);
        }

        // Checked against the resulting state rather than the payload, because a
        // partial update can clear a role, remove a level, or do both in one
        // request.
        $this->guardRoleAssigned(
            $staff->staff_role,
            $staff->custom_role,
            $staff->staff_role?->department() ?? $staff->department,
            $staff->management_level !== null
        );

        $this->guardOneDepartment($additional, $staff->staff_role?->department() ?? $staff->department);
    }

    /**
     * Write one audit row per meaningful change, so a role change is never buried in a rename.
     *
     * @param  array<string, mixed>  $before
     */
    private function recordUpdate(User $actor, User $staff, array $before): void
    {
        $context = ['facility_id' => $staff->facility_id];

        // One entry per role change, carrying the department either side: a
        // move between departments is a role change, never a separate event.
        if ($before['role_label'] !== $staff->roleLabel() || $before['department'] !== $staff->department?->value) {
            $this->auditLogger->record($actor, 'staff.role_changed', $staff, [
                ...$context,
                // Every role either side, main first, custom ones by name.
                'from' => $before['roles'],
                'to' => $this->roleValues($staff),
                'from_department' => $before['department'],
                'to_department' => $staff->department?->value,
            ]);
        }

        if ($before['privileges'] !== $this->privilegeValues($staff)) {
            $this->auditLogger->record($actor, 'staff.privileges_changed', $staff, [
                ...$context,
                'from' => $before['privileges'],
                'to' => $this->privilegeValues($staff),
            ]);
        }

        if ($before['management_level'] !== $staff->management_level?->value) {
            $this->auditLogger->record($actor, 'staff.management_level_changed', $staff, [
                ...$context,
                'from' => $before['management_level'],
                'to' => $staff->management_level?->value,
            ]);
        }

        if ($before['account_status'] !== $staff->account_status?->value) {
            $this->auditLogger->record($actor, 'staff.status_changed', $staff, [
                ...$context,
                'from' => $before['account_status'],
                'to' => $staff->account_status?->value,
            ]);
        }
    }

    /**
     * Refuse a change that would leave the facility with nobody able to manage its staff.
     *
     * Only an Admin counts: a view-only Supervisor cannot manage anyone.
     * Called after the write inside the transaction rather than before it: the
     * cheapest correct way to ask "would this leave zero Admins" is to make
     * the change and count, letting the rollback undo it.
     */
    private function guardAdminFloor(int $facilityId): void
    {
        if ($this->staffRepository->countActiveAdmins($facilityId) > 0) {
            return;
        }

        throw $this->refuse(
            409,
            'last_admin',
            'This facility would be left with no Blood Center Admin. Make another staff member an Admin first.'
        );
    }

    /**
     * Refuse an account with neither a role nor a management level.
     *
     * Such an account holds no abilities at all, so it can sign in and reach
     * nothing. That is the right fail-closed default for an account awaiting
     * assignment, but it is not a state an Admin should be able to create on
     * purpose without noticing.
     */
    private function guardRoleAssigned(?StaffRole $role, ?string $customRole, ?Department $department, bool $hasLevel): void
    {
        if ($role !== null) {
            return;
        }

        if (filled($customRole)) {
            if ($department === null) {
                throw ValidationException::withMessages([
                    'department' => ['Choose the department this role works in.'],
                ]);
            }

            return;
        }

        if ($hasLevel) {
            return;
        }

        throw ValidationException::withMessages([
            'staff_role' => ['Choose or type a role, or give them the Admin or Supervisor level instead.'],
        ]);
    }

    /**
     * Refuse roles from more than one department.
     *
     * The project owner's rule (2026-10-17): a person may hold several roles,
     * but all in one department, so a role blind to donors is never paired
     * with one that sees their names. User's saving hook would drop a stray
     * role silently; this says why first.
     *
     * @param  array<int, StaffRole>  $additional
     */
    private function guardOneDepartment(array $additional, ?Department $department): void
    {
        foreach ($additional as $role) {
            if ($department !== null && $role->department() !== $department) {
                throw ValidationException::withMessages([
                    'additional_staff_roles' => [
                        "{$role->label()} is in {$role->department()->label()}, not {$department->label()}. One person's roles must all be in one department.",
                    ],
                ]);
            }
        }
    }

    /**
     * StaffRole cases from submitted values, without repeats.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, StaffRole>
     */
    private function rolesFrom(array $values): array
    {
        $roles = [];

        foreach ($values as $value) {
            $role = $value instanceof StaffRole ? $value : StaffRole::tryFrom((string) $value);

            if ($role !== null && ! in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Every role an account holds, as the audit trail records it: predefined
     * roles by value, main first, then a custom one by name.
     *
     * @return array<int, string>
     */
    private function roleValues(User $staff): array
    {
        return [
            ...array_map(fn (StaffRole $role): string => $role->value, $staff->staffRoles()),
            ...(filled($staff->custom_role) ? [$staff->custom_role] : []),
        ];
    }

    /**
     * The privileges in force, as values, for comparing before and after.
     *
     * @return array<int, string>
     */
    private function privilegeValues(User $staff): array
    {
        return array_map(
            fn (StaffPrivilege $privilege): string => $privilege->value,
            DepartmentPermissions::privilegesOf($staff)
        );
    }

    /**
     * Resolve a staff account within the caller's facility, or 404.
     */
    private function findOrFail(User $actor, string $uuid, bool $withTrashed = false): User
    {
        $staff = $this->staffRepository->findForFacility(
            $uuid,
            $this->requireFacility($actor)->id,
            $withTrashed
        );

        // Deliberately the same 404 a genuinely unknown uuid produces. A 403
        // here would tell the caller that an account exists at another centre.
        return $staff ?? throw $this->refuse(404, 'staff_not_found', 'No such staff account at this facility.');
    }

    /**
     * The facility the caller acts for, resolved from the token rather than from input.
     */
    private function requireFacility(User $actor): Facility
    {
        $actor->loadMissing('facility');

        return $actor->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Translate a unique-index collision into a field-level validation error.
     *
     * Only reachable when two submissions race past the FormRequest's unique
     * rules and collide at the index.
     */
    private function rethrowUniqueViolation(QueryException $exception): void
    {
        if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
            return;
        }

        $message = $exception->getMessage();

        $field = match (true) {
            str_contains($message, 'employee_id') => 'employee_id',
            str_contains($message, 'phone') => 'phone',
            default => 'email',
        };

        throw ValidationException::withMessages([
            $field => ['This value conflicts with an account that was just created. Please try again.'],
        ]);
    }

    /**
     * Build the project's refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }

    /**
     * Shape one staff account for the roster.
     *
     * Fields are whitelisted rather than inherited from the model so a
     * credential or an internal key is never exposed by accident.
     *
     * @return array<string, mixed>
     */
    private function format(User $staff): array
    {
        return [
            'uuid' => $staff->uuid,
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'full_name' => trim($staff->first_name.' '.$staff->last_name),
            'email' => $staff->email,
            'phone' => $staff->phone,
            'username' => $staff->username,
            'employee_id' => $staff->employee_id,
            // The title: RMT, RN, MD. No job post beside it (project owner, 2026-10-22).
            'credentials' => $staff->credentials,
            // As it prints on what they sign: "Rowena Tan, RMT".
            'signature_name' => $staff->signatureName(),
            'department' => $staff->department?->value,
            'department_label' => $staff->department?->label(),
            'staff_role' => $staff->staff_role?->value,
            'staff_role_label' => $staff->staff_role?->label(),
            // The others, main role excluded; staff_roles is every one, main first.
            'additional_staff_roles' => array_values(array_map(
                fn (StaffRole $role): string => $role->value,
                array_slice($staff->staffRoles(), 1)
            )),
            'staff_roles' => array_map(fn (StaffRole $role): string => $role->value, $staff->staffRoles()),
            'custom_role' => $staff->custom_role,
            'role_label' => $staff->roleLabel(),
            'role_labels' => $staff->roleLabels(),
            'staff_privileges' => $this->privilegeValues($staff),
            'management_level' => $staff->management_level?->value,
            'management_level_label' => $staff->management_level?->label(),
            'account_status' => $staff->account_status?->value,
            'email_verified' => $staff->hasVerifiedEmail(),
            'permissions' => $staff->abilities(),
            'deleted_at' => $staff->deleted_at?->toISOString(),
            'created_at' => $staff->created_at?->toISOString(),
        ];
    }
}
