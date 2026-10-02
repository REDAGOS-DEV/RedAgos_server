<?php

namespace App\Enums;

/**
 * The states one blood unit's hold against one request moves through.
 *
 * This is the row that carries dispatch and receipt, which is why the request's
 * own status does not need to. A unit is `Allocated` while it is held in the
 * fridge for a request, `Released` once it has physically left the facility,
 * and `Cancelled` when the hold is given up and the unit returns to stock.
 *
 * `Released` is not the same as received. Receipt is stamped on the same row by
 * the requesting facility, because only they can assert that blood arrived.
 */
enum AllocationStatus: string
{
    case Allocated = 'allocated';

    case Released = 'released';

    case Cancelled = 'cancelled';

    /**
     * Get every accepted allocation status value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in fulfilment views.
     */
    public function label(): string
    {
        return match ($this) {
            self::Allocated => 'Reserved',
            self::Released => 'Released',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Determine whether this status still lays claim to its blood unit.
     *
     * A claiming allocation is one that must stop any other request taking the
     * same unit. `Cancelled` does not claim, which is what makes a released
     * hold re-allocatable to somebody else.
     */
    public function claimsUnit(): bool
    {
        return match ($this) {
            self::Allocated, self::Released => true,
            self::Cancelled => false,
        };
    }

    /**
     * Get every status that lays claim to its blood unit.
     *
     * @return array<int, string>
     */
    public static function claimingValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->claimsUnit())
        ));
    }
}
