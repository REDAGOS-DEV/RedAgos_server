<?php

namespace App\Policies;

use App\Models\DonationAppointment;
use App\Models\User;

class DonationAppointmentPolicy
{
    /**
     * Determine whether the user may view this appointment.
     */
    public function view(User $user, DonationAppointment $appointment): bool
    {
        return $this->owns($user, $appointment);
    }

    /**
     * Determine whether the user may reschedule this appointment.
     */
    public function update(User $user, DonationAppointment $appointment): bool
    {
        return $this->owns($user, $appointment);
    }

    /**
     * Determine whether the user may cancel this appointment.
     */
    public function delete(User $user, DonationAppointment $appointment): bool
    {
        return $this->owns($user, $appointment);
    }

    /**
     * A donor profile's primary key is the owning user's id, so an appointment
     * belongs to the user whose id matches its donor_id.
     */
    private function owns(User $user, DonationAppointment $appointment): bool
    {
        return (int) $appointment->donor_id === (int) $user->id;
    }
}
