<?php

namespace App\Enums;

/**
 * The states a blood request moves through, from submission to closure.
 *
 * These are the values the Capstone data dictionary declares, plus `Cancelled`
 * for a request the requesting facility withdraws before anything is held for
 * it. The paper's storyboard also speaks of "Submitted" and "Approved", but
 * neither is a state here: "Submitted" is what `Pending` means, and "Approved"
 * is not distinguishable from `Processing`, because approving a request and
 * reserving stock for it happen in one transaction. Both survive as display
 * labels on the client, never as stored values.
 *
 * Dispatch and receipt are deliberately absent. They are properties of the
 * individual units held for a request, so they are recorded on
 * request_allocations and derived from there rather than flattened into a
 * request-wide status that could not describe a partly-dispatched request.
 */
enum BloodRequestStatus: string
{
    case Pending = 'pending';

    case Processing = 'processing';

    case Partial = 'partial';

    case Fulfilled = 'fulfilled';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    /**
     * Get every accepted status value, in the order the column declares them.
     *
     * The blood_requests migration builds its column from this list, so the
     * database and the application cannot disagree about what a request's
     * status may be.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in request listings.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Partial => 'Partially Fulfilled',
            self::Fulfilled => 'Fulfilled',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Determine whether the request has reached a state it can never leave.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Fulfilled, self::Rejected, self::Cancelled => true,
            self::Pending, self::Processing, self::Partial => false,
        };
    }

    /**
     * Determine whether fulfilling staff may still act on the request.
     *
     * `Partial` stays open: a request short-filled from one batch may still
     * receive further units as stock arrives, and closing it here would force
     * the requester to raise a second request for the same patient need.
     */
    public function acceptsAllocation(): bool
    {
        return match ($this) {
            self::Pending, self::Processing, self::Partial => true,
            self::Fulfilled, self::Rejected, self::Cancelled => false,
        };
    }

    /**
     * Determine whether the requesting facility may still withdraw the request.
     *
     * Only before anything is held for it. Once units are reserved the hold has
     * to be released by the facility that owns the stock, so cancellation stops
     * being the requester's decision alone.
     */
    public function isWithdrawable(): bool
    {
        return $this === self::Pending;
    }
}
