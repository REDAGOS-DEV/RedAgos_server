<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Formatting shared by every account-creation flow.
 *
 * Registration exists for donors and for organisations, and both derive a
 * username from the email address and store phone numbers in E.164. Keeping one
 * copy means the flows cannot drift on what a stored value looks like, which
 * matters because users.phone and users.username are both unique.
 */
final class AccountIdentity
{
    /**
     * Normalise a Philippine mobile number to E.164.
     *
     * Separators are stripped first, so "0917 123 4567" and "0917-123-4567"
     * reach the same stored value. Anything not recognisably local or
     * 63-prefixed is returned untouched — the FormRequest regex is what rejects
     * those, not this.
     */
    public static function normalizePhilippinePhone(string $phone): string
    {
        $phone = preg_replace('/[\s-]+/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '09')) {
            return '+63'.substr($phone, 1);
        }

        if (str_starts_with($phone, '63')) {
            return '+'.$phone;
        }

        return $phone;
    }

    /**
     * Derive a username from an email address.
     *
     * The random suffix stops two people sharing a local part on different
     * providers from colliding on the unique username column.
     */
    public static function buildUsername(string $email): string
    {
        return Str::before($email, '@').'-'.Str::lower(Str::random(6));
    }
}
