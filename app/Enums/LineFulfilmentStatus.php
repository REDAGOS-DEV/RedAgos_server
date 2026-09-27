<?php

namespace App\Enums;

/**
 * How far one line of a request has been met.
 *
 * Derived, never stored. It is read from the line's requested quantity, the
 * units released against it, the quantity forwarded to other facilities and
 * whether its remainder was closed, all of which are stored elsewhere; a stored
 * copy would be a second truth that drifts the moment one of them changes.
 */
enum LineFulfilmentStatus: string
{
    case Unfulfilled = 'unfulfilled';

    case Partial = 'partial';

    case Fulfilled = 'fulfilled';

    case Forwarded = 'forwarded';

    case ClosedShort = 'closed_short';

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Unfulfilled',
            self::Partial => 'Partially Fulfilled',
            self::Fulfilled => 'Fulfilled',
            self::Forwarded => 'Forwarded to another facility',
            self::ClosedShort => 'Closed — not supplied',
        };
    }
}
