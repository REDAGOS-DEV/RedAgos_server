<?php

namespace App\Repository;

use App\Enums\FacilityStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class BloodCenterRepository
{
    /**
     * The canonical facility type every blood-centre registration is filed under.
     */
    private const FACILITY_TYPE = 'blood_center';

    /**
     * Get the id of the canonical blood-centre facility type.
     *
     * Public because RegisterBloodCenterRequest needs it to scope the centre
     * name uniqueness rule to the same index the table carries,
     * unique(facility_type_id, name).
     */
    public function bloodCenterTypeId(): int
    {
        return FacilityType::firstOrCreate(['name' => self::FACILITY_TYPE])->id;
    }

    /**
     * Create a facility from a registration, awaiting approval.
     *
     * facility_type_id is resolved here rather than read from the payload, so a
     * client cannot file itself under a different organisation type. status is
     * assigned directly because it is deliberately not mass-assignable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFacility(array $attributes): Facility
    {
        $facility = new Facility;

        $facility->fill($attributes);
        $facility->facility_type_id = $this->bloodCenterTypeId();
        $facility->status = FacilityStatus::PendingApproval;
        $facility->save();

        return $facility;
    }

    /**
     * Create a staff user attached to a facility.
     *
     * facility_id is assigned directly: it is the facility-isolation boundary,
     * so no mass-assignment path may reach it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createStaffUser(array $attributes, Facility $facility): User
    {
        $user = new User;

        $user->fill($attributes);
        $user->facility_id = $facility->id;
        $user->save();

        return $user;
    }

    /**
     * Record the one user permitted to resubmit this facility's registration.
     */
    public function setRegistrationContact(Facility $facility, User $user): Facility
    {
        $facility->registration_contact_user_id = $user->id;
        $facility->save();

        return $facility;
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
     * List facility registrations in a given state, newest first.
     *
     * @return LengthAwarePaginator<int, Facility>
     */
    public function registrationsByStatus(FacilityStatus $status, int $perPage): LengthAwarePaginator
    {
        return Facility::query()
            ->with(['facilityType', 'registrationContact', 'approver'])
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->paginate($perPage);
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
