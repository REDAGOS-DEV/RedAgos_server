<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A person's name as it appears on an ID: letters, and only the punctuation
 * real names carry — "Ma. Luisa", "Dela Cruz", "Santos-Reyes", "O'Brien".
 *
 * Letters are any script's (\pL), with their combining accents (Mn, Mc), so
 * "Peña" passes whether it was typed precomposed or decomposed. Emoji, digits
 * and other symbols are refused: a name is printed on the DHQ, the bag label
 * and the donor card, and is what the counter searches on.
 *
 * Variation selectors are combining marks too, but they only ever restyle an
 * emoji, so one left behind in "Ma❤️ria" would otherwise slip through as an
 * accent. Enclosing marks (the keycap in "1️⃣") are not accents and are not in
 * the set.
 *
 * Both apostrophes are accepted because a phone keyboard types the curly one.
 */
class PersonName implements ValidationRule
{
    public const PATTERN = "/^\\pL[\\pL\\p{Mn}\\p{Mc}'’. -]*$/u";

    private const VARIATION_SELECTORS = '/[\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            ! is_string($value)
            || preg_match(self::PATTERN, $value) !== 1
            || preg_match(self::VARIATION_SELECTORS, $value) === 1
        ) {
            $fail('The :attribute can only contain letters, spaces, hyphens, apostrophes and periods.');
        }
    }
}
