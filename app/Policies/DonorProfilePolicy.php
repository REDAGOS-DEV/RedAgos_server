<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\DonorProfile;
use App\Models\User;

class DonorProfilePolicy
{
    /**
     * Determine whether the user may view this donor's identity document.
     *
     * A government ID is not a profile photo. Only the donor it belongs to and
     * the platform administrators who review it may open one; blood-centre staff
     * fall through to false and verify the physical card at the counter instead.
     * No department ability grants this, so a supervisor cannot earn it either.
     */
    public function viewIdentityDocument(User $user, DonorProfile $profile): bool
    {
        return $this->owns($user, $profile) || $user->hasRole(RoleName::Admin);
    }

    /**
     * A donor profile's primary key is the owning user's id.
     */
    private function owns(User $user, DonorProfile $profile): bool
    {
        return (int) $profile->donor_id === (int) $user->id;
    }
}
