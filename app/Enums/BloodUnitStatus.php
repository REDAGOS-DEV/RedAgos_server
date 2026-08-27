<?php

namespace App\Enums;

enum BloodUnitStatus: string
{
    case Available = 'available';

    case Reserved = 'reserved';

    case Issued = 'issued';

    case Expired = 'expired';

    case Discarded = 'discarded';

    /**
     * Get every accepted status value, in the order the column declares them.
     *
     * This is the canonical list. The blood_units migration builds its column
     * from it and the API projects it, so the database and the application
     * cannot disagree about what a unit's status may be.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in inventory filters.
     */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Issued => 'Issued',
            self::Expired => 'Expired',
            self::Discarded => 'Discarded',
        };
    }
}
