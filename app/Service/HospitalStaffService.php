<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\HospitalRole;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Support\AccountIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A hospital blood bank's staff, kept by its Head (project owner, 2026-10-11).
 *
 * The Head adds colleagues with a role (HospitalRole), changes their role or
 * title, and removes or restores their accounts. Accounts are created as the
 * blood centre's StaffService creates its own: the Head sets the first
 * password, the colleague verifies their address, and the account holds the
 * blood_bank role from the start, because the facility is necessarily
 * approved (the staff routes sit behind facility.operational).
 *
 * A blood bank always keeps at least one active Head, so nobody is left able
 * to manage it. Every lookup is scoped to the Head's own facility; another
 * blood bank's account is a 404, never a 403.
 */
class HospitalStaffService
{
    public function __construct(
        private readonly BloodCenterRepository $accounts,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * The blood bank's accounts, removed ones included, and the roles a colleague can hold.
     *
     * @return array<string, mixed>
     */
    public function list(User $actor): array
    {
        $facility = $this->requireFacility($actor);

        $staff = User::withTrashed()
            ->where('facility_id', $facility->id)
            ->whereNotNull('hospital_role')
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return [
            'data' => $staff->map(fn (User $member): array => $this->format($member, $actor))->all(),
            'roles' => array_map(fn (HospitalRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ], HospitalRole::cases()),
        ];
    }

    /**
     * Add a colleague with their role.
     *
     * @param  array<string, mixed>  $payload  Validated by StoreHospitalStaffRequest.
     * @return array<string, mixed>
     */
    public function create(User $actor, array $payload): array
    {
        $facility = $this->requireFacility($actor);
        $role = HospitalRole::from($payload['hospital_role']);

        try {
            $staff = DB::transaction(function () use ($facility, $payload, $role): User {
                $created = $this->accounts->createStaffUser([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => trim($payload['first_name']),
                    'last_name' => trim($payload['last_name']),
                    // Already lowercased and normalised to E.164 by the request.
                    'email' => $payload['email'],
                    'phone' => $payload['phone'] ?? null,
                    'username' => AccountIdentity::buildUsername($payload['email']),
                    'password' => Hash::make($payload['password']),
                    'account_status' => AccountStatus::PendingVerification,
                    'position' => isset($payload['position']) ? trim((string) $payload['position']) : null,
                ], $facility);

                $created->hospital_role = $role;
                $created->save();

                // syncWithoutDetaching, never sync, which would strip roles.
                $this->accounts->attachRole($created, RoleName::BloodBank->value);

                return $created;
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // Sent after the commit so a rolled-back creation never mails a live link.
        $staff->sendEmailVerificationNotification();

        $this->auditLogger->record($actor, 'hospital_staff.created', $staff, [
            'facility_id' => $facility->id,
            'hospital_role' => $role->value,
        ]);

        return [
            'message' => $staff->first_name.' has been added as '.$role->label().'.',
            'data' => $this->format($staff, $actor),
        ];
    }

    /**
     * Change a colleague's role or title.
     *
     * @param  array<string, mixed>  $payload  Validated by UpdateHospitalStaffRequest.
     * @return array<string, mixed>
     */
    public function update(User $actor, string $uuid, array $payload): array
    {
        $facility = $this->requireFacility($actor);
        $staff = $this->findOrFail($facility, $uuid);
        $before = $staff->hospital_role;

        DB::transaction(function () use ($facility, $staff, $payload): void {
            // Serialises changes to one blood bank's roster, so two Heads
            // demoting each other at once cannot both pass the floor check.
            $this->accounts->lockFacility($facility->id);

            if (array_key_exists('hospital_role', $payload)) {
                $staff->hospital_role = HospitalRole::from($payload['hospital_role']);
            }

            if (array_key_exists('position', $payload)) {
                $staff->position = filled($payload['position']) ? trim((string) $payload['position']) : null;
            }

            $staff->save();

            $this->guardHeadFloor($facility->id);
        });

        if ($before !== $staff->hospital_role) {
            $this->auditLogger->record($actor, 'hospital_staff.role_changed', $staff, [
                'facility_id' => $facility->id,
                'previous_role' => $before?->value,
                'hospital_role' => $staff->hospital_role?->value,
            ]);
        }

        return [
            'message' => $staff->first_name.' is now '.$staff->hospital_role?->label().'.',
            'data' => $this->format($staff, $actor),
        ];
    }

    /**
     * Remove a colleague's account and end their sessions.
     *
     * @return array<string, mixed>
     */
    public function delete(User $actor, string $uuid): array
    {
        $facility = $this->requireFacility($actor);
        $staff = $this->findOrFail($facility, $uuid);

        DB::transaction(function () use ($facility, $staff): void {
            $this->accounts->lockFacility($facility->id);

            $staff->delete();

            $this->guardHeadFloor($facility->id);
        });

        $staff->tokens()->delete();

        $this->auditLogger->record($actor, 'hospital_staff.deleted', $staff, [
            'facility_id' => $facility->id,
        ]);

        return ['message' => $staff->first_name."'s account has been removed."];
    }

    /**
     * Restore a removed colleague, with the role they had.
     *
     * @return array<string, mixed>
     */
    public function restore(User $actor, string $uuid): array
    {
        $facility = $this->requireFacility($actor);
        $staff = $this->findOrFail($facility, $uuid, withTrashed: true);

        if (! $staff->trashed()) {
            throw $this->refuse(409, 'staff_not_deleted', 'This account is already active.');
        }

        $staff->restore();

        $this->auditLogger->record($actor, 'hospital_staff.restored', $staff, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => $staff->first_name."'s account has been restored.",
            'data' => $this->format($staff, $actor),
        ];
    }

    /**
     * Refuse a change that leaves the blood bank with no active Head.
     */
    private function guardHeadFloor(int $facilityId): void
    {
        $heads = User::query()
            ->where('facility_id', $facilityId)
            ->where('hospital_role', HospitalRole::Head->value)
            ->count();

        if ($heads > 0) {
            return;
        }

        throw $this->refuse(409, 'last_head', 'The blood bank would be left with no Head. Make another colleague Head first.');
    }

    private function findOrFail(Facility $facility, string $uuid, bool $withTrashed = false): User
    {
        $staff = User::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where('uuid', $uuid)
            ->where('facility_id', $facility->id)
            ->whereNotNull('hospital_role')
            ->first();

        // Deliberately the same 404 a genuinely unknown uuid produces. A 403
        // here would tell the caller that an account exists at another facility.
        return $staff ?? throw $this->refuse(404, 'staff_not_found', 'No such staff account at this blood bank.');
    }

    private function requireFacility(User $actor): Facility
    {
        $actor->loadMissing('facility');

        return $actor->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Turn a unique violation from a concurrent creation into a validation error.
     */
    private function rethrowUniqueViolation(QueryException $exception): void
    {
        if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
            return;
        }

        $field = str_contains($exception->getMessage(), 'phone') ? 'phone' : 'email';

        throw ValidationException::withMessages([
            $field => ['This value conflicts with an account that was just created. Please try again.'],
        ]);
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function format(User $staff, User $actor): array
    {
        return [
            'uuid' => $staff->uuid,
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'full_name' => trim($staff->first_name.' '.$staff->last_name),
            'email' => $staff->email,
            'phone' => $staff->phone,
            'position' => $staff->position,
            'hospital_role' => $staff->hospital_role?->value,
            'hospital_role_label' => $staff->hospital_role?->label(),
            'account_status' => $staff->account_status?->value,
            'email_verified' => $staff->hasVerifiedEmail(),
            'is_you' => $staff->id === $actor->id,
            'deleted_at' => $staff->deleted_at?->toISOString(),
            'created_at' => $staff->created_at?->toISOString(),
        ];
    }
}
