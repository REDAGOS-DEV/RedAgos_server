<?php

namespace App\Support;

/**
 * The initials a facility's documents carry when it has uploaded no logo.
 *
 * A billing document is headed by the centre that issued it, never by a shared
 * mark, so a centre without a logo still gets a mark of its own: up to two
 * initials of its name, skipping the small joining words.
 */
final class FacilityMonogram
{
    /**
     * Words that are never an initial: "Bureau of Blood" is "BB", not "BO".
     *
     * @var array<int, string>
     */
    private const SKIPPED = ['of', 'the', 'and', 'de', 'del', 'ng', 'sa', 'for'];

    /**
     * Up to two initials of a facility name: "Davao Regional Blood Center" gives "DR".
     */
    public static function of(?string $name): string
    {
        $words = preg_split('/[\s\-]+/u', trim((string) $name)) ?: [];

        $initials = array_map(
            fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)),
            array_values(array_filter(
                $words,
                fn (string $word): bool => preg_match('/^\p{L}/u', $word) === 1
                    && ! in_array(mb_strtolower($word), self::SKIPPED, true)
            ))
        );

        return implode('', array_slice($initials, 0, 2));
    }
}
