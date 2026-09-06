<?php

namespace App\Service;

use App\Enums\BloodUnitStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * What a signed-in blood-centre account may do with its own record.
 *
 * Registration used to live here too. It does not any more: facilities are
 * created by a Super Admin through FacilityManagementService, so there is no
 * self-service path onto this service at all.
 */
class BloodCenterService
{
    public function __construct(
        private readonly BloodCenterRepository $bloodCenterRepository
    ) {}

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
}
