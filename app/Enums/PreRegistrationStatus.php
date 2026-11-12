<?php

namespace App\Enums;

/**
 * Where a drive pre-registration stands as a planning record. Never a
 * statement about eligibility, attendance or donation: those come from the
 * appointment and the donation the existing workflow records.
 */
enum PreRegistrationStatus: string
{
    case PreRegistered = 'pre_registered';

    case Withdrawn = 'withdrawn';

    /**
     * Get every accepted status value, in the order the column declares them.
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
            self::PreRegistered => 'Pre-registered',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * Whether the person is still on the drive's list.
     */
    public function isOnList(): bool
    {
        return $this !== self::Withdrawn;
    }
}
