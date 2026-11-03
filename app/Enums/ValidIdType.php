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

    case Tin = 'tin';

    case PhilHealth = 'philhealth';

    case PagIbig = 'pagibig';

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
            self::Tin => 'TIN ID',
            self::PhilHealth => 'PhilHealth ID',
            self::PagIbig => 'Pag-IBIG ID',
        };
    }

    /**
     * The pattern this ID's number matches once normalised (capitals, no
     * spaces or hyphens), or null for an ID whose numbering is not fixed here.
     *
     * Kept in step with the client's utils/validId.ts, which hyphenates the
     * number as it is typed and checks the same shapes before it is sent.
     */
    public function numberPattern(): ?string
    {
        return match ($this) {
            self::PhilSys => '/^\d{16}$/',
            self::DriversLicense => '/^[A-Z]\d{10}$/',
            self::Tin => '/^\d{9}(\d{3})?$/',
            self::SssId => '/^\d{10}$/',
            self::Umid, self::PhilHealth, self::PagIbig => '/^\d{12}$/',
            self::Passport => '/^[A-Z]\d{7}[A-Z]$/',
            default => null,
        };
    }

    /**
     * What the number is, with an example written without symbols, for the
     * error when one does not match numberPattern().
     */
    public function numberFormat(): ?string
    {
        return match ($this) {
            self::PhilSys => 'PhilSys number: 16 digits, e.g. 1234567890123456',
            self::DriversLicense => "Driver's license number: 1 letter and 10 digits, e.g. A1234567890",
            self::Tin => 'TIN: 9 or 12 digits, e.g. 123456789 or 123456789000',
            self::SssId => 'SSS number: 10 digits, e.g. 1234567890',
            self::Umid => 'UMID number (CRN): 12 digits, e.g. 123456789012',
            self::PhilHealth => 'PhilHealth number: 12 digits, e.g. 123456789012',
            self::PagIbig => 'Pag-IBIG number: 12 digits, e.g. 123456789012',
            self::Passport => 'Passport number: 1 letter, 7 digits and 1 letter, e.g. P1234567A',
            default => null,
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
