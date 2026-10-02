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
     * Normalise a government ID number to its comparable form.
     *
     * donor_profiles.valid_id_number is unique and is what the counter searches
     * on, so "PH-DL-1234", "ph dl 1234" and "phdl1234" must not be three
     * different donors. Case and every separator are dropped.
     *
     * Null is returned when nothing survives: an empty string is a real value to
     * a unique rule, so two donors submitting punctuation alone would collide
     * with each other rather than simply having no ID on file.
     */
    public static function normalizeValidIdNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', Str::upper($number)) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Mask a stored ID number down to its last four characters.
     *
     * Administrators reviewing a submission read the number off the document
     * image; the queue itself has no reason to carry it in full.
     */
    public static function maskValidIdNumber(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        return str_repeat('•', max(strlen($number) - 4, 0)).substr($number, -4);
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
