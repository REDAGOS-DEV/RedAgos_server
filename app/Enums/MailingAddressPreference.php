<?php

namespace App\Enums;

/**
 * Which address the donor asks to be written to.
 *
 * Section I-A of the DOH form has the donor tick "Home Address" or "Office
 * Address" above the address lines. The tick is the preference; the addresses
 * themselves are donor_profiles.address and donor_profiles.office_address.
 */
enum MailingAddressPreference: string
{
    case Home = 'home';

    case Office = 'office';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home Address',
            self::Office => 'Office Address',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $preference): array => ['value' => $preference->value, 'label' => $preference->label()],
            self::cases()
        );
    }
}
