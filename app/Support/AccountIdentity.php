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

    /**
     * The donor code printed on a donor's card, e.g. DONOR-000015.
     */
    public static function donorCode(int $donorId): string
    {
        return 'DONOR-'.str_pad((string) $donorId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The donor id a donor code names, or null if the text is not one.
     *
     * The same pattern the counter searches with (DonorDirectoryRepository), so
     * "donor-000015" and "DONOR-15" both read as donor 15.
     */
    public static function donorIdFromCode(string $code): ?int
    {
        return preg_match('/^DONOR-(\d{1,10})$/i', trim($code), $match) === 1
            ? (int) $match[1]
            : null;
    }

    /**
     * A temporary password for the counter's sign-in slip.
     *
     * Read off paper and typed on a phone, so ten characters from an alphabet
     * with no look-alikes (no 0/O/o, 1/l/I), and at least one capital, one
     * small letter and one digit. random_int() is cryptographically secure.
     */
    public static function temporaryPassword(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $all = $upper.$lower.$digits;

        $pick = fn (string $from): string => $from[random_int(0, strlen($from) - 1)];

        $characters = [$pick($upper), $pick($lower), $pick($digits)];

        while (count($characters) < 10) {
            $characters[] = $pick($all);
        }

        // Shuffled with random_int too: shuffle() is not cryptographically secure.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
