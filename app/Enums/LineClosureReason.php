<?php

namespace App\Enums;

/**
 * Why the rest of a request line will not be supplied by the facility handling it.
 *
 * Each reason belongs to one side of the exchange. The fulfilling centre closes
 * a line it cannot supply; the requesting hospital closes a replenishment line
 * it no longer needs. On a Patient Transfusion allocation only the centre
 * closes lines — the hospital closes the rest of the patient's requirement
 * instead — and what a centre could not supply returns to the requirement as
 * unallocated, to be asked of another facility.
 */
enum LineClosureReason: string
{
    case Unavailable = 'unavailable';

    case NotNeeded = 'not_needed';

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
            self::Unavailable => 'Unavailable at this facility',
            self::NotNeeded => 'No longer needed',
        };
    }
}
