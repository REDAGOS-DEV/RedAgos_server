<?php

namespace App\Enums;

/**
 * An ABO group, as read by one grouping method.
 *
 * Forward grouping reads the red cells against anti-A and anti-B; reverse
 * grouping reads the plasma against A and B cells. The two must agree before a
 * typing is trusted — see "Immunohematology clearance" in
 * docs/IMPLEMENTATION_DECISIONS.md.
 */
enum AboGroup: string
{
    case A = 'A';

    case B = 'B';

    case AB = 'AB';

    case O = 'O';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The ABO group of a combined blood-type code such as "AB+" or "O-".
     */
    public static function ofBloodTypeCode(?string $code): ?self
    {
        return $code === null ? null : self::tryFrom(rtrim(strtoupper(trim($code)), '+-'));
    }
}
