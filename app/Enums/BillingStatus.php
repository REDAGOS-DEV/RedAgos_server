<?php

namespace App\Enums;

/**
 * The settlement states of the statement raised against a blood request.
 *
 * `Void` is not in the Capstone data dictionary, which names only Unpaid,
 * Partial and Paid. It is kept because a statement raised in error needs a
 * state that is neither owed nor collected, and because `billings.request_id`
 * is unique — without it, a mistaken statement could never be superseded.
 *
 * Note what `Paid` means for a subsidised request: blood requests are currently
 * funded by government subsidy, so a statement is raised at zero and settled on
 * creation. It is genuinely paid, in the sense the release gate cares about —
 * nothing is owed.
 */
enum BillingStatus: string
{
    case Unpaid = 'unpaid';

    case Partial = 'partial';

    case Paid = 'paid';

    case Void = 'void';

    /**
     * Get every accepted billing status value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown on statements.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partially Paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    /**
     * Determine whether this state clears blood units for release.
     *
     * The Capstone is explicit that no unit may be released without confirmed
     * payment. `Partial` therefore does not clear: part of the money is not
     * the same as the money.
     *
     * `Void` clears because a voided statement is a statement that should never
     * have existed, leaving nothing owed against the request.
     */
    public function clearsRelease(): bool
    {
        return match ($this) {
            self::Paid, self::Void => true,
            self::Unpaid, self::Partial => false,
        };
    }
}
