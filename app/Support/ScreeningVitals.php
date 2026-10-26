<?php

namespace App\Support;

/**
 * The counter's six vital signs and the range each is normal within (project
 * owner, 2026-10-24; config `donation.vitals`).
 *
 * This supersedes, for the counter, the rule that RedAgos never judges the
 * vitals: the physician must now record all six, and a reading outside its
 * range is what unlocks (and suggests) the vital-sign deferral codes. The
 * physician still decides; nothing here accepts or defers anyone.
 *
 * The client reads the same table (ranges()) so the form can warn as the
 * physician types; the server checks again when the screening is saved.
 */
final class ScreeningVitals
{
    /**
     * @return array<int, string>
     */
    public static function fields(): array
    {
        return array_keys((array) config('donation.vitals', []));
    }

    /**
     * The ranges as they apply to one donor, for the client.
     *
     * @return array<string, array{label: string, unit: string, min: float|null, max: float|null, low_code: string|null, high_code: string|null}>
     */
    public static function ranges(?string $sex): array
    {
        $ranges = [];

        foreach ((array) config('donation.vitals', []) as $field => $rule) {
            $ranges[$field] = [
                'label' => (string) $rule['label'],
                'unit' => (string) $rule['unit'],
                'min' => self::bound($rule['min'] ?? null, $sex, 'max'),
                'max' => self::bound($rule['max'] ?? null, $sex, 'min'),
                'low_code' => $rule['low_code'] ?? null,
                'high_code' => $rule['high_code'] ?? null,
            ];
        }

        return $ranges;
    }

    /**
     * @return array<int, string>
     */
    public static function vitalOnlyCodes(): array
    {
        return array_values((array) config('donation.vital_only_codes', []));
    }

    /**
     * The fields with no reading.
     *
     * @param  array<string, mixed>  $readings
     * @return array<int, string>
     */
    public static function missing(array $readings): array
    {
        return array_values(array_filter(
            self::fields(),
            fn (string $field): bool => ! is_numeric($readings[$field] ?? null)
        ));
    }

    /**
     * Each reading outside its range, with the code it suggests.
     *
     * @param  array<string, mixed>  $readings
     * @return array<int, array{field: string, direction: string, code: string|null}>
     */
    public static function findings(array $readings, ?string $sex): array
    {
        $findings = [];

        foreach (self::ranges($sex) as $field => $range) {
            $value = $readings[$field] ?? null;

            if (! is_numeric($value)) {
                continue;
            }

            if ($range['min'] !== null && (float) $value < $range['min']) {
                $findings[] = ['field' => $field, 'direction' => 'low', 'code' => $range['low_code']];
            } elseif ($range['max'] !== null && (float) $value > $range['max']) {
                $findings[] = ['field' => $field, 'direction' => 'high', 'code' => $range['high_code']];
            }
        }

        return $findings;
    }

    /**
     * Whether a code may be recorded with these readings: any code not tied
     * to a vital sign, or a vital-sign code a reading suggests.
     *
     * @param  array<string, mixed>  $readings
     */
    public static function allows(string $code, array $readings, ?string $sex): bool
    {
        $vitalOnly = array_map('mb_strtolower', self::vitalOnlyCodes());

        if (! in_array(mb_strtolower($code), $vitalOnly, true)) {
            return true;
        }

        foreach (self::findings($readings, $sex) as $finding) {
            if ($finding['code'] !== null && mb_strtolower($finding['code']) === mb_strtolower($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bound for one donor: a number, or one per sex. An unrecorded sex
     * takes the stricter: the higher minimum, the lower maximum.
     */
    private static function bound(mixed $bound, ?string $sex, string $stricter): ?float
    {
        if ($bound === null) {
            return null;
        }

        if (! is_array($bound)) {
            return (float) $bound;
        }

        if ($sex !== null && isset($bound[$sex])) {
            return (float) $bound[$sex];
        }

        $values = array_map('floatval', array_values($bound));

        return $stricter === 'max' ? max($values) : min($values);
    }
}
