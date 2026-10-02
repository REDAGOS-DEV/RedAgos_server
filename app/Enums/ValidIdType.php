<?php

namespace App\Enums;

/**
 * The government-issued IDs a donor may present.
 *
 * Kept to the identification the PhilHealth/DOH counter already accepts. The
 * stored value is the machine key; label() is what the donor and staff read.
 */
enum ValidIdType: string
{
    case PhilSys = 'philsys';

    case Umid = 'umid';

    case DriversLicense = 'drivers_license';

    case Passport = 'passport';

    case PostalId = 'postal_id';

    case PrcId = 'prc_id';

    case VotersId = 'voters_id';

    case SssId = 'sss_id';

    /**
     * Get the human-readable name of this ID type.
     */
    public function label(): string
    {
        return match ($this) {
            self::PhilSys => 'PhilSys (National ID)',
            self::Umid => 'UMID',
            self::DriversLicense => "Driver's License",
            self::Passport => 'Passport',
            self::PostalId => 'Postal ID',
            self::PrcId => 'PRC ID',
            self::VotersId => "Voter's ID",
            self::SssId => 'SSS ID',
        };
    }

    /**
     * Get every ID type value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get every ID type as a value/label pair, for the client's picker.
     *
     * @return array<int, array<string, string>>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases()
        );
    }
}
