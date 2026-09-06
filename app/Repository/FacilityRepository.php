<?php

namespace App\Repository;

use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Persistence for administrator-created facility accounts.
 *
 * Deliberately separate from BloodCenterRepository, which serves one facility
 * acting on its own data. This one serves the Super Admin acting across all of
 * them, and is the only place a facility row is created now that public
 * self-registration is gone.
 */
class FacilityRepository
{
    /**
     * Resolve the id of a facility type, creating the row if no seeder has.
     */
    public function typeId(FacilityTypeName $type): int
    {
        return FacilityType::firstOrCreate(['name' => $type->value])->id;
    }

    /**
     * Create a facility already cleared to operate, stamped with who cleared it.
     *
     * facility_type_id, status, approved_at and approved_by are assigned
     * directly rather than filled. They decide which role the facility's
     * accounts receive and whether it may touch real blood stock, so no
     * mass-assignment path may reach them — the type comes from a validated
     * enum and the approval trail from the authenticated administrator, never
     * from request input.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createApprovedFacility(array $attributes, FacilityTypeName $type, User $admin): Facility
    {
        $facility = new Facility;

        $facility->fill($attributes);
        $facility->facility_type_id = $this->typeId($type);
        $facility->status = FacilityStatus::Approved;
        $facility->approved_at = now();
        $facility->approved_by = $admin->id;
        $facility->rejection_reason = null;
        $facility->save();

        return $facility;
    }

    /**
     * Create the facility's primary account, holding the supervisor level.
     *
     * facility_id and is_supervisor are assigned directly for the same reason
     * the approval trail is: the first is the facility-isolation boundary and
     * the second grants every ability in the portal. department stays null —
     * a supervisor holds the full ability set regardless of where they sit,
     * and the primary account is management rather than a posting.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPrimaryAccount(array $attributes, Facility $facility): User
    {
        $user = new User;

        $user->fill($attributes);
        $user->facility_id = $facility->id;
        $user->department = null;
        $user->is_supervisor = true;
        $user->save();

        return $user;
    }

    /**
     * Record which account is the facility's primary one.
     *
     * The column keeps its historical name, registration_contact_user_id, from
     * when facilities self-registered. It has always held the same thing — the
     * one account that speaks for the facility — so it is reused rather than
     * duplicated by a migration.
     */
    public function setPrimaryAccount(Facility $facility, User $user): Facility
    {
        $facility->registration_contact_user_id = $user->id;
        $facility->save();

        return $facility;
    }

    /**
     * Attach a role without disturbing roles the user already holds.
     */
    public function attachRole(User $user, RoleName $role): void
    {
        $user->roles()->syncWithoutDetaching([
            Role::firstOrCreate(['name' => $role->value])->id,
        ]);
    }

    /**
     * Determine whether a username is already taken.
     *
     * Soft-deleted accounts are counted: users.username is unique across the
     * whole table, so a trashed row still occupies the name.
     */
    public function usernameExists(string $username): bool
    {
        return User::withTrashed()->where('username', $username)->exists();
    }

    /**
     * Page every facility, newest first, optionally narrowed.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Facility>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Facility::query()
            ->with(['facilityType', 'primaryAccount', 'approver'])
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status)
            )
            ->when(
                $filters['facility_type'] ?? null,
                fn (Builder $query, string $type): Builder => $query->whereHas(
                    'facilityType',
                    fn (Builder $inner): Builder => $inner->where('name', $type)
                )
            )
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where(
                    fn (Builder $inner): Builder => $inner
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('doh_license_number', 'like', '%'.$search.'%')
                )
            )
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
