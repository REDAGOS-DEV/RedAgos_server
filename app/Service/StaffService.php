<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Repository\StaffRepository;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Roster management for one blood centre, performed by its supervisor.
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
        $isSupervisor = (bool) ($payload['is_supervisor'] ?? false);
        $department = isset($payload['department']) ? Department::from($payload['department']) : null;

        $this->guardDepartmentAssigned($department, $isSupervisor);

        try {
            $staff = DB::transaction(function () use ($facility, $payload, $department, $isSupervisor): User {
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
                    'position' => isset($payload['position']) ? trim((string) $payload['position']) : null,
                ], $facility, $department, $isSupervisor);

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
            'department' => $department?->value,
            'is_supervisor' => $isSupervisor,
        ]);

        return [
            'message' => $staff->first_name.' has been added to your team.',
            'data' => $this->format($staff),
        ];
    }

    /**
     * Update a colleague's department, management level, posting or account status.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $actor, string $uuid, array $payload): array
    {
        $facility = $this->requireFacility($actor);
        $staff = $this->findOrFail($actor, $uuid);

        $before = [
            'department' => $staff->department?->value,
            'is_supervisor' => $staff->is_supervisor,
            'account_status' => $staff->account_status?->value,
        ];

        try {
            $staff = DB::transaction(function () use ($facility, $staff, $payload): User {
                // Serialise on the facility row before touching anything that
                // could reduce the supervisor count. Two concurrent demotions
                // would otherwise both read "two supervisors" and both proceed.
                $this->bloodCenterRepository->lockFacility($facility->id);

                $this->applyUpdate($staff, $payload);
                $staff->save();

                $this->guardSupervisorFloor($facility->id);

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

            $this->guardSupervisorFloor($facility->id);
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
     * that omits a department leaves the existing posting alone rather than
     * clearing it.
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

        if (array_key_exists('position', $payload)) {
            $staff->position = $payload['position'] === null ? null : trim((string) $payload['position']);
        }

        if (array_key_exists('department', $payload)) {
            $staff->department = $payload['department'] === null
                ? null
                : Department::from($payload['department']);
        }

        if (array_key_exists('is_supervisor', $payload)) {
            $staff->is_supervisor = (bool) $payload['is_supervisor'];
        }

        if (array_key_exists('account_status', $payload)) {
            $staff->account_status = AccountStatus::from($payload['account_status']);
        }

        // Checked against the resulting state rather than the payload, because a
        // partial update can clear a department, demote a supervisor, or do both
        // in one request.
        $this->guardDepartmentAssigned($staff->department, $staff->is_supervisor);
    }

    /**
     * Write one audit row per meaningful change, so a role change is never buried in a rename.
     *
     * @param  array<string, mixed>  $before
     */
    private function recordUpdate(User $actor, User $staff, array $before): void
    {
        $context = ['facility_id' => $staff->facility_id];

        if ($before['department'] !== $staff->department?->value) {
            $this->auditLogger->record($actor, 'staff.department_changed', $staff, [
                ...$context,
                'from' => $before['department'],
                'to' => $staff->department?->value,
            ]);
        }

        if ($before['is_supervisor'] !== $staff->is_supervisor) {
            $this->auditLogger->record(
                $actor,
                $staff->is_supervisor ? 'staff.supervisor_granted' : 'staff.supervisor_revoked',
                $staff,
                $context
            );
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
     * Refuse a change that would leave the facility with nobody able to manage it.
     *
     * Called after the write inside the transaction rather than before it: the
     * cheapest correct way to ask "would this leave zero supervisors" is to
     * make the change and count, letting the rollback undo it.
     */
    private function guardSupervisorFloor(int $facilityId): void
    {
        if ($this->staffRepository->countActiveSupervisors($facilityId) > 0) {
            return;
        }

        throw $this->refuse(
            409,
            'last_supervisor',
            'This facility would be left with no supervisor. Promote another staff member first.'
        );
    }

    /**
     * Refuse a non-supervisor who has been left without a department.
     *
     * Such an account holds no abilities at all, so it can sign in and reach
     * nothing. That is the right fail-closed default for an account awaiting
     * assignment, but it is not a state a supervisor should be able to create
     * on purpose without noticing.
     */
    private function guardDepartmentAssigned(?Department $department, bool $isSupervisor): void
    {
        if ($isSupervisor || $department !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'department' => ['Select a department, or grant the supervisor level instead.'],
        ]);
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
            'position' => $staff->position,
            'department' => $staff->department?->value,
            'department_label' => $staff->department?->label(),
            'is_supervisor' => (bool) $staff->is_supervisor,
            'account_status' => $staff->account_status?->value,
            'email_verified' => $staff->hasVerifiedEmail(),
            'permissions' => $staff->abilities(),
            'deleted_at' => $staff->deleted_at?->toISOString(),
            'created_at' => $staff->created_at?->toISOString(),
        ];
    }
}
