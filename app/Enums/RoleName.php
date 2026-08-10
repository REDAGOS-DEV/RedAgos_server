<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum RoleName: string
{
    case Admin = 'admin';

    case Donor = 'donor';

    case BloodCenter = 'blood_center';

    case BloodBank = 'blood_bank';

    /**
     * Aliases the frontend sends that do not match the canonical database value.
     *
     * The role-selection screen uses kebab-case ids and calls a blood bank a
     * "hospital", so incoming values are normalised before any role comparison.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'blood-center' => 'blood_center',
        'bloodcenter' => 'blood_center',
        'blood-bank' => 'blood_bank',
        'bloodbank' => 'blood_bank',
        'hospital' => 'blood_bank',
    ];

    /**
     * Resolve a client-supplied role identifier to its canonical role, if one exists.
     */
    public static function normalize(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = Str::lower(trim($value));

        return self::tryFrom(self::ALIASES[$normalized] ?? $normalized);
    }

    /**
     * Get every value accepted for this role, including frontend aliases.
     *
     * @return array<int, string>
     */
    public function acceptedValues(): array
    {
        $aliases = array_keys(array_filter(
            self::ALIASES,
            fn (string $canonical): bool => $canonical === $this->value
        ));

        return [$this->value, ...$aliases];
    }

    /**
     * Get every role identifier the API accepts, canonical values and aliases alike.
     *
     * @return array<int, string>
     */
    public static function allAcceptedValues(): array
    {
        return [
            ...array_column(self::cases(), 'value'),
            ...array_keys(self::ALIASES),
        ];
    }
}
