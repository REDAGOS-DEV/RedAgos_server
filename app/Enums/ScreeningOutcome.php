<?php

namespace App\Enums;

enum ScreeningOutcome: string
{
    case Qualified = 'qualified';

    case Deferred = 'deferred';

    /**
     * Get every accepted outcome value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in the counter workflow.
     */
    public function label(): string
    {
        return match ($this) {
            self::Qualified => 'Qualified',
            self::Deferred => 'Deferred',
        };
    }

    /**
     * Determine whether this outcome permits the donation to proceed to collection.
     */
    public function permitsCollection(): bool
    {
        return $this === self::Qualified;
    }
}
