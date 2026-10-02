<?php

namespace App\Enums;

/**
 * Where a blood request was keyed in.
 *
 * A separate axis from RequestPurpose and never to be merged with it. A walk-in
 * is still a Patient Transfusion request from the patient's hospital; what
 * differs is that the watcher brought it to a blood centre, which confirmed it
 * with the hospital by phone and entered it on the hospital's behalf.
 */
enum RequestSource: string
{
    case BloodBankPortal = 'blood_bank_portal';

    case BloodCenterWalkIn = 'blood_center_walk_in';

    /**
     * Get every accepted source value, in the order the column declares them.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in request listings.
     */
    public function label(): string
    {
        return match ($this) {
            self::BloodBankPortal => 'Blood Bank Portal',
            self::BloodCenterWalkIn => 'Blood Center Walk-in',
        };
    }

    /**
     * Determine whether the request was entered at a blood centre for a watcher.
     */
    public function isWalkIn(): bool
    {
        return $this === self::BloodCenterWalkIn;
    }
}
