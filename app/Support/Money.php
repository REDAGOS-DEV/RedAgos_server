<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Peso amounts as whole centavos.
 *
 * Billing compares what is owed with what was collected, and a float cannot
 * hold most two-decimal amounts exactly: 0.1 + 0.2 is not 0.3. Every billing
 * sum and comparison is therefore done on integer centavos, converted once
 * where an amount enters — a stored decimal, a validated request field, a
 * provider's figure — and converted back only to store or to print.
 *
 * An amount with a fraction of a centavo is refused rather than rounded. The
 * columns hold two decimals and the request rules allow two, so a third digit
 * is a data error, and rounding it away would hide one.
 */
final class Money
{
    /**
     * Tolerance for a float that only differs from its two-decimal form by binary noise.
     */
    private const FLOAT_NOISE = 0.000001;

    /**
     * Convert a stored, submitted or provider amount to whole centavos.
     */
    public static function toCentavos(int|float|string $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }

        if (is_float($amount)) {
            return self::fromFloat($amount);
        }

        return self::fromString($amount);
    }

    /**
     * Render centavos as the two-decimal string the database columns store.
     */
    public static function toDecimal(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $absolute = abs($centavos);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Render centavos as a JSON number, for response and audit fields whose shape predates this class.
     *
     * Output only. Never compare or add the result: that is what centavos are for.
     */
    public static function toFloat(int $centavos): float
    {
        return (float) self::toDecimal($centavos);
    }

    /**
     * Read a float through its two-decimal form.
     *
     * Floats arrive from JSON request bodies and from SQLite, never from
     * PostgreSQL's numeric. Formatting first means the decimal digits are
     * read, rather than a binary approximation multiplied by a hundred.
     */
    private static function fromFloat(float $amount): int
    {
        if (! is_finite($amount)) {
            throw new InvalidArgumentException('An amount must be a finite number.');
        }

        $formatted = number_format($amount, 2, '.', '');

        if (abs((float) $formatted - $amount) > self::FLOAT_NOISE) {
            throw new InvalidArgumentException("An amount cannot carry a fraction of a centavo: {$amount}.");
        }

        return self::fromString($formatted);
    }

    /**
     * Read a decimal string digit by digit, without passing through a float.
     */
    private static function fromString(string $amount): int
    {
        $trimmed = trim($amount);

        if (preg_match('/^(-?)(\d{1,15})(?:\.(\d+))?$/', $trimmed, $parts) !== 1) {
            throw new InvalidArgumentException("Not an amount: \"{$amount}\".");
        }

        $fraction = $parts[3] ?? '';

        if (strlen($fraction) > 2) {
            if (trim(substr($fraction, 2), '0') !== '') {
                throw new InvalidArgumentException("An amount cannot carry a fraction of a centavo: \"{$amount}\".");
            }

            $fraction = substr($fraction, 0, 2);
        }

        $centavos = ((int) $parts[2]) * 100 + (int) str_pad($fraction, 2, '0');

        return $parts[1] === '-' ? -$centavos : $centavos;
    }
}
