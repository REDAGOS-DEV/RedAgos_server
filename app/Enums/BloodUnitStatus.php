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
     * On the shelf but not yet cleared by testing. Booked in this way, and
     * released to Available only on both clearance tokens.
     */
    case Quarantined = 'quarantined';

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
     * The statuses of a unit that has left quarantine: released on both
     * clearances, whatever became of it since. Once a donation has one, the
     * test results that released it can no longer be corrected.
     *
     * @return array<int, string>
     */
    public static function leftQuarantineValues(): array
    {
        return [
            self::Available->value,
            self::Reserved->value,
            self::Issued->value,
            self::Expired->value,
        ];
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
            self::Quarantined => 'Quarantined',
        };
    }
}
