<?php

namespace App\Enums;

/**
 * The blood bag the phlebotomist drew into, as Section II of the DOH form prints it.
 *
 * RECORD ONLY. The bag decides how many components a donation can physically be
 * separated into, but nothing here enforces that against the Processing
 * department's component breakdown — the project owner chose to record the bag
 * and leave the breakdown to the people separating it.
 */
enum BloodBagType: string
{
    case Single = 'single';

    case Double = 'double';

    case Triple = 'triple';

    /**
     * Get every accepted bag value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown at the counter and in the laboratory.
     */
    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Double => 'Double',
            self::Triple => 'Triple',
        };
    }

    /**
     * Get the letter the form prints beside each box: (S), (D) or (T).
     */
    public function code(): string
    {
        return match ($this) {
            self::Single => 'S',
            self::Double => 'D',
            self::Triple => 'T',
        };
    }

    /**
     * Get every bag as a value/label pair, for the counter's picker.
     *
     * @return array<int, array<string, string>>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $bag): array => [
                'value' => $bag->value,
                'label' => $bag->label(),
                'code' => $bag->code(),
            ],
            self::cases()
        );
    }
}
