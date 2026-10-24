<?php

namespace App\Enums;

/**
 * The type of donation, picked at the counter when the donation is opened:
 * the TYPE OF DONOR on the centre's collection record.
 */
enum DonationType: string
{
    /** A voluntary donation, for no patient in particular. */
    case WalkIn = 'walk_in';

    /** For a patient, by a donor of the patient's blood type. */
    case TypeSpecific = 'ts';

    /** For a patient, by a donor of another blood type. */
    case NonTypeSpecific = 'nts';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::TypeSpecific => 'TS',
            self::NonTypeSpecific => 'NTS',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
