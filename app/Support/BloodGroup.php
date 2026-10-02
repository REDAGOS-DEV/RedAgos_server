<?php

namespace App\Support;

/**
 * The ABO group and the Rh factor, read off one stored blood type code.
 *
 * `blood_types.code` holds both fused — "AB+" — but the DOH forms print them
 * apart: the request form in two boxes, the stock inventory sheet as separate
 * Rh-positive and Rh-negative tables. This is the one place the code is split.
 */
final class BloodGroup
{
    /**
     * The ABO groups in the order the DOH stock sheet prints its rows.
     *
     * @var array<int, string>
     */
    public const ABO_ORDER = ['A', 'B', 'O', 'AB'];

    /**
     * The ABO group alone: "AB+" gives "AB".
     */
    public static function abo(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return rtrim($code, '+-');
    }

    /**
     * The Rh factor: "positive", "negative", or null when the code carries none.
     */
    public static function rh(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return match (substr($code, -1)) {
            '+' => 'positive',
            '-' => 'negative',
            default => null,
        };
    }

    /**
     * Sort position for a code: Rh-positive A, B, O, AB, then Rh-negative A, B, O, AB.
     */
    public static function sortKey(string $code): int
    {
        $abo = array_search(self::abo($code), self::ABO_ORDER, true);
        $abo = $abo === false ? count(self::ABO_ORDER) : $abo;

        return (self::rh($code) === 'negative' ? 10 : 0) + $abo;
    }
}
