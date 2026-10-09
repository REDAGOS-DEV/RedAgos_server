<?php

namespace App\Enums;

/**
 * Where one blood type and component stands against its facility's minimum.
 *
 * Derived on every read from the units and the threshold, never stored: a
 * stored level would be a second source of truth for stock.
 *
 * `Unmonitored` means no minimum is set for the cell, which says nothing about
 * whether it is well stocked. `Critical` is an empty shelf under a minimum,
 * kept apart from `Low` because nothing at all can be issued.
 */
enum StockLevel: string
{
    case Unmonitored = 'unmonitored';

    case Ok = 'ok';

    case Low = 'low';

    case Critical = 'critical';

    /**
     * Judge a count against a minimum, or against none.
     */
    public static function judge(int $available, ?int $minimum): self
    {
        if ($minimum === null) {
            return self::Unmonitored;
        }

        if ($available >= $minimum) {
            return self::Ok;
        }

        return $available === 0 ? self::Critical : self::Low;
    }

    /**
     * Determine whether this level is a breach of the minimum.
     */
    public function isBreach(): bool
    {
        return $this === self::Low || $this === self::Critical;
    }

    /**
     * Get every level value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
