<?php

namespace App\Enums;

/**
 * How a replenishment order reaches the hospital: it collects, or the centre brings it.
 */
enum HandoverMethod: string
{
    case Pickup = 'pickup';

    case Delivery = 'delivery';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Pick-up',
            self::Delivery => 'Delivery',
        };
    }
}
