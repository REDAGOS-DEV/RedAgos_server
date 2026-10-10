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
 * `Subsidised` is also outside the dictionary, and exists because "nothing was
 * owed" and "the money was collected" are different facts about a request. A
 * blood centre funded by government subsidy releases blood without charging
 * for it; recording that as Paid would let any revenue report count fees the
 * network never charged. It clears release exactly as Paid does.
 *
 * A statement raised at zero because the component carries no price is still
 * Paid: nothing was waived, there was simply nothing to charge. Subsidised is
 * a decision somebody took.
 *
 * `StatementOnly` is the statement of a weekly (replenishment) order. The
 * project owner decided on 2026-10-10 that only the patient or watcher of a
 * Patient Transfusion owes money in RedAgos; a weekly order is billed to the
 * hospital by statement and settled outside the system. Nothing is collected
 * against it, it clears release, and its total still follows the units
 * reserved. That is a recorded exception to the Capstone rule that no unit
 * leaves without confirmed payment, for weekly orders only.
 *
 * `SettledOutside` is a statement-only statement the hospital has since
 * settled, outside RedAgos, with billing staff recording its reference and
 * date (owner, 2026-10-11). No money moved through RedAgos; the statement is
 * closed, and its total no longer follows anything.
 */
enum BillingStatus: string
{
    case Unpaid = 'unpaid';

    case Partial = 'partial';

    case Paid = 'paid';

    case Void = 'void';

    case Subsidised = 'subsidised';

    case StatementOnly = 'statement_only';

    case SettledOutside = 'settled_outside';

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
            self::Subsidised => 'Government Subsidised',
            self::StatementOnly => 'Statement Only',
            self::SettledOutside => 'Settled by Hospital',
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
     *
     * `Subsidised` clears for the same reason it exists: the government has met
     * the cost, so nothing is owed by the hospital and the units may go.
     *
     * `StatementOnly` clears because nothing is owed in RedAgos at all: the
     * weekly order is settled outside the system, by owner decision. So does
     * `SettledOutside`, the same statement once the hospital has settled it.
     */
    public function clearsRelease(): bool
    {
        return match ($this) {
            self::Paid, self::Void, self::Subsidised, self::StatementOnly, self::SettledOutside => true,
            self::Unpaid, self::Partial => false,
        };
    }

    /**
     * Determine whether this statement is closed to further change.
     *
     * Re-pricing on a later allocation must not revive a statement somebody has
     * deliberately settled to zero — voiding says it should never have existed,
     * subsidy says the government met the cost, and silently turning either
     * back into an unpaid balance would undo that decision.
     *
     * StatementOnly is not settled by decision: its total keeps following the
     * units reserved, only its status stays put. SettledOutside is: the
     * hospital settled the figure it was billed, which must not move after.
     */
    public function isSettledByDecision(): bool
    {
        return $this === self::Void || $this === self::Subsidised || $this === self::SettledOutside;
    }

    /**
     * Determine whether money is collected in RedAgos against this statement at all.
     *
     * False for a voided or subsidised statement, which somebody settled by
     * decision, and for a statement-only one, which is settled outside the
     * system. Only a collectible statement takes a payment or a checkout, and
     * only its status is worked out from what it has collected.
     */
    public function isCollectible(): bool
    {
        return match ($this) {
            self::Unpaid, self::Partial, self::Paid => true,
            self::Void, self::Subsidised, self::StatementOnly, self::SettledOutside => false,
        };
    }

    /**
     * Determine whether money was actually collected against this statement.
     *
     * The distinction a revenue report needs: a subsidised or voided statement
     * cleared its request without a peso changing hands, and a statement-only
     * one was settled outside RedAgos.
     */
    public function representsCollectedMoney(): bool
    {
        return $this === self::Paid || $this === self::Partial;
    }
}
