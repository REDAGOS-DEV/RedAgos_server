<?php

namespace App\Enums;

/**
 * The unexpected-antibody screen on a donor sample.
 *
 * A positive screen does not reject the donation, but it does hold its
 * immunohematology clearance: the antibody has to be identified — the
 * Reference Laboratory Consultant's work — before the unit can be released.
 */
enum AntibodyScreen: string
{
    case Negative = 'negative';

    case Positive = 'positive';

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
            self::Negative => 'Negative',
            self::Positive => 'Positive',
        };
    }
}
