<?php

namespace App\Enums;

/**
 * How far one component of a patient's requirement has come.
 *
 * Derived by TransfusionRequestResolver from the facility allocations, never
 * stored. The difference between "awaiting response" and "needs allocation"
 * is the one the hospital acts on: the first is waiting on a centre, the
 * second is waiting on the hospital to ask somebody.
 */
enum TransfusionLineStatus: string
{
    case AwaitingResponse = 'awaiting_response';

    case NeedsAllocation = 'needs_allocation';

    case PartiallyApproved = 'partially_approved';

    case Approved = 'approved';

    case PartiallyFulfilled = 'partially_fulfilled';

    case Fulfilled = 'fulfilled';

    case ClosedShort = 'closed_short';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingResponse => 'Awaiting facility response',
            self::NeedsAllocation => 'Needs allocation',
            self::PartiallyApproved => 'Partially approved',
            self::Approved => 'Approved',
            self::PartiallyFulfilled => 'Partially fulfilled',
            self::Fulfilled => 'Fulfilled',
            self::ClosedShort => 'Closed — not supplied',
        };
    }
}
