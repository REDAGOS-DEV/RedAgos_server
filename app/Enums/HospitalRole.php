<?php

namespace App\Enums;

/**
 * The posts a hospital blood bank's staff member can be given.
 *
 * Added by the project owner on 2026-10-11, when receiving became two steps:
 * the requesting side confirms the bags arrived, and inventory staff stock
 * them in. Before then every hospital account did the same job.
 *
 * The Head holds everything and manages the blood bank's staff; the facility's
 * primary account is its Head. The ability sets are in HospitalPermissions.
 */
enum HospitalRole: string
{
    case Head = 'head';

    case InventoryStaff = 'inventory_staff';

    case RequestingStaff = 'requesting_staff';

    /**
     * Get every accepted role value, in the order the staff form lists them.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable title shown on the staff roster.
     */
    public function label(): string
    {
        return match ($this) {
            self::Head => 'Blood Bank Head',
            self::InventoryStaff => 'Inventory Staff',
            self::RequestingStaff => 'Requesting Staff',
        };
    }

    /**
     * Get what the role is for, as the staff form explains it.
     */
    public function description(): string
    {
        return match ($this) {
            self::Head => 'Everything in the hospital portal, and the blood bank\'s staff accounts.',
            self::InventoryStaff => 'Stocks bags in, tags, crossmatches, transfuses, returns and discards them, receives blood from outside RedAgos, and sets stock thresholds.',
            self::RequestingStaff => 'Requests blood, keeps the request days, confirms dispatched blood arrived, and reads billing documents.',
        };
    }
}
