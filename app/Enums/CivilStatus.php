<?php

namespace App\Enums;

/**
 * The civil status options on Section I-A of the DOH questionnaire.
 *
 * Recorded because the form asks for it. Nothing in RedAgos decides anything
 * from this value -- it is transcribed onto the questionnaire the counter reads.
 */
enum CivilStatus: string
{
    case Single = 'single';

    case Married = 'married';

    case Widowed = 'widowed';

    case Separated = 'separated';

    case Annulled = 'annulled';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Married => 'Married',
            self::Widowed => 'Widowed',
            self::Separated => 'Separated',
            self::Annulled => 'Annulled',
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
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases()
        );
    }
}
