<?php

namespace App\Enums;

/**
 * The organisation types a facility account can be created for.
 *
 * facility_types is a database table, so the set of rows is open in principle.
 * This enum is the closed subset the application actually reasons about: the
 * two types a Super Admin may onboard, and the role each one's staff hold.
 * Keeping the mapping here is what stops the creation flow hard-coding blood
 * centres and bolting the blood bank on beside it.
 */
enum FacilityTypeName: string
{
    case BloodCenter = 'blood_center';

    case BloodBank = 'blood_bank';

    /**
     * Get the role every account at a facility of this type holds.
     */
    public function role(): RoleName
    {
        return match ($this) {
            self::BloodCenter => RoleName::BloodCenter,
            self::BloodBank => RoleName::BloodBank,
        };
    }

    /**
     * Get the name this type is shown under in the administrator interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::BloodCenter => 'Blood Center',
            self::BloodBank => 'Hospital Blood Bank',
        };
    }

    /**
     * Determine whether facilities of this type take donor bookings.
     *
     * Only a blood centre collects from donors. A hospital blood bank draws on
     * centres instead, so the operating-slot configuration is meaningful for
     * one type and not the other — which is what "operating details, if
     * applicable" comes down to.
     */
    public function acceptsDonorBookings(): bool
    {
        return $this === self::BloodCenter;
    }

    /**
     * Get every facility type value a Super Admin may create.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
