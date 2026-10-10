<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One canonical form for the values a correction compares.
 *
 * A correction stores the record as it stood and the values it should become,
 * and approval asks whether the record has moved since. Compared as stored,
 * "2026-10-09" and "2026-10-09 00:00:00", "1500" and "1500.00", or "" and null
 * all differ, and a correction would be refused as stale when nothing changed.
 * Every value is put through here before it is stored or compared.
 *
 * Used by the Issuance and Billing subjects only. The donation subjects keep
 * the values exactly as their own writes validate them. A null stays null
 * whatever the type, so "no answer" never reads as false.
 */
final class CorrectionValues
{
    public const DATETIME = 'datetime';

    public const DATE = 'date';

    public const MONEY = 'money';

    public const STRING = 'string';

    /**
     * A yes or no, such as whether a payment is to be voided.
     */
    public const BOOLEAN = 'boolean';

    /**
     * Put one value in canonical form.
     */
    public static function normalize(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            self::DATETIME => self::moment($value)->utc()->format('Y-m-d\TH:i:s\Z'),
            self::DATE => self::moment($value)->toDateString(),
            self::MONEY => Money::toDecimal(Money::toCentavos($value)),
            self::STRING => self::text($value),
            self::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /**
     * Normalise the fields a subject declares, dropping any it does not.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $types  Field => type.
     * @return array<string, mixed>
     */
    public static function normalizeAll(array $values, array $types): array
    {
        $normalised = [];

        foreach ($types as $field => $type) {
            if (array_key_exists($field, $values)) {
                $normalised[$field] = self::normalize($values[$field], $type);
            }
        }

        return $normalised;
    }

    /**
     * Whether two values are the same once both are in canonical form.
     */
    public static function same(mixed $a, mixed $b, string $type): bool
    {
        return self::normalize($a, $type) === self::normalize($b, $type);
    }

    private static function moment(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
