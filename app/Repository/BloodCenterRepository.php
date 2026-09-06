<?php

namespace App\Repository;

use App\Enums\Department;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence for one blood centre acting on its own data.
 *
 * Creating a facility is not here: that belongs to FacilityRepository, which
 * serves the Super Admin acting across all of them.
 */
class BloodCenterRepository
{
    /**
     * Create a staff user attached to a facility.
     *
     * facility_id, department and is_supervisor are all assigned directly
     * rather than filled: the first is the facility-isolation boundary and the
     * other two decide what the account may do, so no mass-assignment path may
     * reach any of them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createStaffUser(
        array $attributes,
        Facility $facility,
        ?Department $department = null,
        bool $isSupervisor = false
    ): User {
        $user = new User;

        $user->fill($attributes);
        $user->facility_id = $facility->id;
        $user->department = $department;
        $user->is_supervisor = $isSupervisor;
        $user->save();

        return $user;
    }

    /**
     * Re-read a facility under a row lock for a decision that must not race.
     *
     * Two administrators approving at the same moment would otherwise both
     * attach the role and both write an approval timestamp.
     */
    public function lockFacility(int $facilityId): ?Facility
    {
        return Facility::query()
            ->whereKey($facilityId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Every user account attached to a facility.
     *
     * @return Collection<int, User>
     */
    public function staffForFacility(int $facilityId): Collection
    {
        return User::query()
            ->where('facility_id', $facilityId)
            ->get();
    }

    /**
     * Attach a role without disturbing roles the user already holds.
     */
    public function attachRole(User $user, string $roleName): void
    {
        $user->roles()->syncWithoutDetaching([
            Role::firstOrCreate(['name' => $roleName])->id,
        ]);
    }

    /**
     * @return Collection<int, BloodType>
     */
    public function bloodTypes(): Collection
    {
        return BloodType::query()->orderBy('id')->get();
    }

    /**
     * @return Collection<int, BloodComponent>
     */
    public function components(): Collection
    {
        return BloodComponent::query()->orderBy('name')->get();
    }
}
