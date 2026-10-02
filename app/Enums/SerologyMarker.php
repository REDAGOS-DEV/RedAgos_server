<?php

namespace App\Enums;

/**
 * The transfusion-transmissible-infection panel the Testing department records.
 *
 * Exactly five, fixed by the project owner. The DOH form's Serology & NAT table
 * also prints "NAT" and "Others"; neither is recorded. This enum is the single
 * definition of the panel: the request rules, the table's columns and the
 * Testing page are all built from it, so the value doubles as the column name
 * on `donation_serology`.
 */
enum SerologyMarker: string
{
    case Hiv = 'hiv';

    case Hbsag = 'hbsag';

    case Hcv = 'hcv';

    case Syphilis = 'syphilis';

    case Malaria = 'malaria';

    /**
     * Get every marker value, in the order the form prints them.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the label shown to the Testing department.
     *
     * Exhaustive on purpose, with no `default`: a marker added without a label
     * should fail loudly rather than print a raw value on a clinical record.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hiv => 'HIV',
            self::Hbsag => 'HBsAg (Hepatitis B)',
            self::Hcv => 'HCV (Hepatitis C)',
            self::Syphilis => 'Syphilis',
            self::Malaria => 'Malaria',
        };
    }

    /**
     * Get every marker as a value/label pair, for the Testing page.
     *
     * @return array<int, array<string, string>>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $marker): array => [
                'value' => $marker->value,
                'label' => $marker->label(),
            ],
            self::cases()
        );
    }
}
