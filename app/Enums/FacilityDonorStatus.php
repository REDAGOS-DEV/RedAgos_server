<?php

namespace App\Enums;

/**
 * Whether a donor is still active with one blood centre.
 *
 * The centre's own call, on its own record (`facility_donors`): marking a donor
 * inactive here says nothing to any other centre. An inactive donor stays on
 * the list and keeps their history; they are left out of eligibility
 * reminders.
 */
enum FacilityDonorStatus: string
{
    case Active = 'active';

    case Inactive = 'inactive';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
