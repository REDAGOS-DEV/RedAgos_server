<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\FacilityStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
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

class BloodCenterService
{
    public function __construct(
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Register a blood centre and the staff account that applied for it.
     *
     * No role is attached here. The blood_center role is granted only by
     * FacilityApprovalService::approve(), which is what stops a stranger
     * self-provisioning access to real blood stock.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        try {
            $user = DB::transaction(function () use ($payload): User {
                $facility = $this->bloodCenterRepository->createFacility([
                    'name' => trim($payload['center_name']),
                    'doh_license_number' => trim($payload['doh_license_number']),
                    'contact_person' => trim($payload['contact_first_name'].' '.$payload['contact_last_name']),
                    'email' => $payload['email'],
                    'phone' => $payload['phone'],
                    'address' => trim($payload['address']),
                    'description' => isset($payload['description']) ? trim((string) $payload['description']) : null,
                ]);

                $user = $this->bloodCenterRepository->createStaffUser([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => trim($payload['contact_first_name']),
                    'last_name' => trim($payload['contact_last_name']),
                    // Already lowercased and normalised to E.164 by the request.
                    'email' => $payload['email'],
                    'phone' => $payload['phone'],
                    'username' => AccountIdentity::buildUsername($payload['email']),
                    'password' => Hash::make($payload['password']),
                    'account_status' => AccountStatus::PendingVerification,
                    'position' => trim($payload['position']),
                ], $facility);

                $this->bloodCenterRepository->setRegistrationContact($facility, $user);

                return $user;
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // Sent after the commit so a rolled-back registration never mails a
        // live verification link.
        $user->sendEmailVerificationNotification();

        $facility = $user->facility;

        $this->auditLogger->record($user, 'blood_center.registered', $facility, [
            'facility_id' => $facility?->id,
        ]);

        return [
            'message' => 'Registration submitted. We will email you once an administrator has reviewed your DOH licence.',
            'data' => [
                'facility' => [
                    'id' => $facility?->id,
                    'name' => $facility?->name,
                    'status' => $facility?->status->value,
                ],
                'user' => [
                    'uuid' => $user->uuid,
                    'email' => $user->email,
                    'account_status' => $user->account_status?->value,
                    'roles' => [],
                ],
            ],
        ];
    }

    /**
     * Report where an applicant's registration currently stands.
     *
     * @return array<string, mixed>
     */
    public function registrationStatus(User $user): array
    {
        $facility = $this->requireFacility($user);

        return [
            'facility' => [
                'id' => $facility->id,
                'name' => $facility->name,
                'status' => $facility->status->value,
                'rejection_reason' => $facility->rejection_reason,
                'resubmitted_at' => $facility->resubmitted_at?->toIso8601String(),
                // Returned so the resubmission form can prefill. Making an
                // applicant retype their DOH licence from memory invites a typo
                // that then fails the uniqueness check for the wrong reason.
                'doh_license_number' => $facility->doh_license_number,
                'contact_person' => $facility->contact_person,
                'address' => $facility->address,
                'description' => $facility->description,
            ],

            // Mirrors exactly what resubmit() will allow, so the UI never offers
            // a button the API would refuse.
            'can_resubmit' => $this->canResubmit($user, $facility),
        ];
    }

    /**
     * Resubmit a rejected registration for another review.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resubmit(User $user, array $payload): array
    {
        $facility = $this->requireFacility($user);

        // Authorisation before state: only the registration contact may
        // resubmit, not every member of staff who ends up attached later.
        if ($facility->registration_contact_user_id !== $user->id) {
            throw new HttpResponseException(response()->json([
                'message' => 'Only the person who submitted this registration can resubmit it.',
                'code' => 'not_registration_contact',
            ], 403));
        }

        if ($facility->status !== FacilityStatus::Rejected) {
            throw new HttpResponseException(response()->json([
                'message' => 'This registration is not awaiting resubmission.',
                'code' => 'facility_not_rejected',
            ], 409));
        }

        try {
            DB::transaction(function () use ($facility, $payload): void {
                $facility->fill([
                    'name' => trim($payload['center_name']),
                    'doh_license_number' => trim($payload['doh_license_number']),
                    'contact_person' => trim($payload['contact_person']),
                    'address' => trim($payload['address']),
                    'description' => isset($payload['description']) ? trim((string) $payload['description']) : null,
                ]);

                $facility->status = FacilityStatus::PendingApproval;
                $facility->rejection_reason = null;
                $facility->resubmitted_at = now();
                $facility->save();
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        $this->auditLogger->record($user, 'facility.resubmitted', $facility, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => 'Registration resubmitted. An administrator will review it again.',
            'facility' => [
                'id' => $facility->id,
                'name' => $facility->name,
                'status' => $facility->status->value,
                'resubmitted_at' => $facility->resubmitted_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Read the caller's own staff profile and facility.
     *
     * The facility is resolved from the authenticated user, never from request
     * input, so there is no IDOR surface.
     *
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        $user->loadMissing(['roles', 'facility']);

        return [
            'profile' => [
                'full_name' => trim($user->first_name.' '.$user->last_name),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'employee_id' => $user->employee_id,
                'position' => $user->position,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'facility' => $user->facility ? [
                'id' => $user->facility->id,
                'name' => $user->facility->name,
                'address' => $user->facility->address,
                'doh_license_number' => $user->facility->doh_license_number,
                'status' => $user->facility->status->value,
                'operating_hours' => $user->facility->operating_hours,
            ] : null,
            'account' => [
                'username' => $user->username,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'account_status' => $user->account_status?->value,
                'email_verified' => $user->hasVerifiedEmail(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Update the caller's own staff fields.
     *
     * The request only validates staff fields, and facility columns are not on
     * User at all, so a facility cannot be edited through this path.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateProfile(User $user, array $payload): array
    {
        $user->fill($payload);
        $user->save();

        return $this->profile($user->refresh());
    }

    /**
     * Change the caller's password and end every existing session.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function updatePassword(User $user, array $payload): array
    {
        if (! Hash::check($payload['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // The hashed cast on User handles hashing.
        $user->password = $payload['password'];
        $user->save();

        // A password change must not leave older tokens usable.
        $user->tokens()->delete();

        return [
            'message' => 'Password updated successfully. Please sign in again.',
        ];
    }

    /**
     * Serve the dropdown data the inventory screens need.
     *
     * @return array<string, mixed>
     */
    public function referenceData(User $user): array
    {
        // Guaranteed non-null: this only runs behind facility.operational, which
        // refuses a caller without an approved facility.
        $facility = $this->requireFacility($user);

        return [
            'blood_types' => $this->bloodCenterRepository->bloodTypes()
                ->map(fn (BloodType $type): array => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'label' => $type->label,
                ])->all(),

            'components' => $this->bloodCenterRepository->components()
                ->map(fn (BloodComponent $component): array => [
                    'id' => $component->id,
                    'name' => $component->name,
                    'shelf_life_days' => $component->shelf_life_days,
                    'storage_temperature' => $component->storage_temperature,
                    // Surfaced so the UI can disable stock entry outright rather
                    // than let someone record a unit with an invented expiry.
                    'shelf_life_configured' => $component->hasShelfLife(),
                ])->all(),

            // Projected from the PHP enum, never from a runtime schema lookup,
            // so the payload is identical on MySQL, Postgres and Supabase.
            'statuses' => array_map(
                fn (BloodUnitStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                BloodUnitStatus::cases()
            ),

            'storage_locations' => config('blood_center.storage_locations', []),

            'facility' => [
                'id' => $facility->id,
                'facility_name' => $facility->name,
                'address' => $facility->address,
            ],
        ];
    }

    /**
     * Determine whether this user may resubmit this facility's registration.
     */
    private function canResubmit(User $user, Facility $facility): bool
    {
        return $facility->status === FacilityStatus::Rejected
            && $facility->registration_contact_user_id === $user->id;
    }

    /**
     * Resolve the caller's facility or refuse.
     */
    private function requireFacility(User $user): Facility
    {
        $user->loadMissing('facility');

        if (! $user->facility) {
            throw new HttpResponseException(response()->json([
                'message' => 'This account is not linked to a facility.',
                'code' => 'facility_missing',
            ], 404));
        }

        return $user->facility;
    }

    /**
     * Translate a unique-index collision into a field-level validation error.
     *
     * Only reachable when two submissions race past the FormRequest's unique
     * rules and collide at the index, so this is the race guard rather than the
     * everyday path. Matching on the column name is driver-dependent, so an
     * unrecognised collision still yields a usable 422 rather than a 500.
     */
    private function rethrowUniqueViolation(QueryException $exception): void
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        // MySQL and sqlite report 23000; PostgreSQL reports 23505.
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return;
        }

        $message = $exception->getMessage();

        $field = match (true) {
            str_contains($message, 'doh_license_number') => 'doh_license_number',
            str_contains($message, 'phone') => 'phone',
            str_contains($message, 'email') => 'email',
            default => 'center_name',
        };

        throw ValidationException::withMessages([
            $field => ['This registration conflicts with one that was just submitted. Please check the details and try again.'],
        ]);
    }
}
