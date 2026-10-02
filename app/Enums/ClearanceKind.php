<?php

namespace App\Enums;

/**
 * The two clearances a donation's units need before they may leave quarantine.
 *
 * Each is issued by the department that owns the result, never by the one
 * releasing the stock: TTI Testing's validated serology panel, and
 * Immunohematology's concordant typing. The Inventory Control Officer can
 * release a unit only when both exist — see docs/IMPLEMENTATION_DECISIONS.md,
 * "Quarantine lifecycle".
 */
enum ClearanceKind: string
{
    case Tti = 'tti';

    case Immunohematology = 'immunohematology';

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
            self::Tti => 'TTI screening cleared',
            self::Immunohematology => 'Blood typing cleared',
        };
    }
}
