<?php

namespace App\Enums;

/**
 * Why the rest of a request line will not be supplied by the facility handling it.
 *
 * Each reason belongs to one side of the exchange. The fulfilling centre closes
 * a line it cannot supply; the requesting hospital closes a line it no longer
 * needs. The difference matters afterwards: stock the centre could not supply
 * may still be sourced from another facility, while a need the hospital has
 * dropped may not.
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

    /**
     * Determine whether the closed remainder may still be sourced from another facility.
     */
    public function allowsForwarding(): bool
    {
        return $this === self::Unavailable;
    }
}
