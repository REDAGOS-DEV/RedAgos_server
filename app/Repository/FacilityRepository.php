<?php

namespace App\Repository;

use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\ManagementLevel;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

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
        // A hospital a blood centre already named on a walk-in becomes this
        // facility rather than a second record of the same name, so its walk-in
        // requests are the new facility's from the start (project owner,
        // 2026-10-19).
        $facility = $type === FacilityTypeName::BloodBank
            ? $this->findUnregisteredBloodBank((string) ($attributes['name'] ?? '')) ?? new Facility
            : new Facility;

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
     * Create the facility's primary account, holding the Admin level.
     *
     * facility_id and management_level are assigned directly for the same
     * reason the approval trail is: the first is the facility-isolation
     * boundary and the second lets the account manage the centre's staff.
     * staff_role stays null — the primary account is management rather than
     * a posting, and creates the colleagues who do the department work.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPrimaryAccount(array $attributes, Facility $facility): User
    {
        $user = new User;

        $user->fill($attributes);
        $user->facility_id = $facility->id;
        $user->staff_role = null;
        $user->management_level = ManagementLevel::Admin;
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
     * The hospital blood bank a walk-in's typed name means: a registered one
     * of that name first, then one a centre already named, ignoring case and
     * spacing, so two counters typing the same hospital share one record.
     */
    public function findWalkInHospitalByName(string $name): ?Facility
    {
        $key = $this->nameKey($name);

        if ($key === '') {
            return null;
        }

        return Facility::query()
            ->whereHas('facilityType', fn (Builder $query): Builder => $query->where('name', FacilityTypeName::BloodBank->value))
            ->whereIn('status', [FacilityStatus::Approved->value, FacilityStatus::Unregistered->value])
            ->whereRaw('LOWER(name) = ?', [$key])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [FacilityStatus::Approved->value])
            ->first();
    }

    /**
     * An unregistered hospital blood bank of this name, ignoring case and spacing.
     */
    public function findUnregisteredBloodBank(string $name): ?Facility
    {
        $key = $this->nameKey($name);

        if ($key === '') {
            return null;
        }

        return Facility::query()
            ->whereHas('facilityType', fn (Builder $query): Builder => $query->where('name', FacilityTypeName::BloodBank->value))
            ->where('status', FacilityStatus::Unregistered->value)
            ->whereRaw('LOWER(name) = ?', [$key])
            ->first();
    }

    /**
     * Record a hospital not on RedAgos by the name a walk-in gave it.
     *
     * Nothing else is known, so nothing else is kept: no address, no accounts,
     * no approval. If another counter created the same name a moment ago, its
     * record is the one returned.
     */
    public function createUnregisteredBloodBank(string $name): Facility
    {
        $clean = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);

        try {
            $facility = new Facility;
            $facility->name = $clean;
            $facility->facility_type_id = $this->typeId(FacilityTypeName::BloodBank);
            $facility->status = FacilityStatus::Unregistered;
            $facility->save();

            return $facility;
        } catch (QueryException $exception) {
            return $this->findUnregisteredBloodBank($clean) ?? throw $exception;
        }
    }

    /**
     * A name as compared: trimmed, single-spaced, lower-cased.
     */
    private function nameKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? trim($name));
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
            // A hospital known only from a walk-in is not a facility anyone
            // manages; it is listed only when asked for by its status.
            ->when(
                ($filters['status'] ?? null) !== FacilityStatus::Unregistered->value,
                fn (Builder $query): Builder => $query->where('status', '!=', FacilityStatus::Unregistered->value)
            )
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
