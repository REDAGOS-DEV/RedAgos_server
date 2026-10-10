<?php

namespace App\Enums;

/**
 * What a billing transaction was.
 *
 * Signed against the bill: a charge raises what is owed, everything that
 * settles it lowers it, and a void puts back the payment it cancels. A
 * correction carries only the change in amount. An opening balance is the
 * history carried in when the journal began, and is written by nothing else.
 */
enum TransactionType: string
{
    case Charge = 'charge';

    case ChargeAdjustment = 'charge_adjustment';

    case Payment = 'payment';

    case PaymentCorrection = 'payment_correction';

    case PaymentVoid = 'payment_void';

    case Subsidy = 'subsidy';

    case ExternalSettlement = 'external_settlement';

    case OpeningBalance = 'opening_balance';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The types that move money at the counter or through the gateway: what was collected.
     *
     * @return array<int, self>
     */
    public static function collections(): array
    {
        return [self::Payment, self::PaymentCorrection, self::PaymentVoid];
    }

    /**
     * The types that change what a bill charges.
     *
     * @return array<int, self>
     */
    public static function charges(): array
    {
        return [self::Charge, self::ChargeAdjustment, self::OpeningBalance];
    }

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Charge',
            self::ChargeAdjustment => 'Charge adjustment',
            self::Payment => 'Payment',
            self::PaymentCorrection => 'Payment correction',
            self::PaymentVoid => 'Payment void',
            self::Subsidy => 'Government subsidy',
            self::ExternalSettlement => 'Settled by hospital',
            self::OpeningBalance => 'Opening balance',
        };
    }
}
