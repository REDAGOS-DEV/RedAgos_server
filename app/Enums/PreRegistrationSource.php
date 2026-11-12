<?php

namespace App\Enums;

/**
 * Who put a person on a drive's list, both through the drive's registration
 * link: the person themselves, or a partner organisation for its people.
 * Staff do not enter pre-registrations (project owner, 2026-11-11).
 */
enum PreRegistrationSource: string
{
    case Individual = 'self';

    case Partner = 'partner';

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
     * Get the label staff read on the drive's registrations.
     */
    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Self',
            self::Partner => 'Partner',
        };
    }
}
