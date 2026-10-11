<?php

namespace App\Enums;

/**
 * How a blood service fee was settled.
 *
 * Cash is confirmed by billing staff at the counter; GCash is the paper's
 * named electronic method. A GCash transfer taken at the counter is recorded
 * from its reference number rather than verified against a provider.
 *
 * A card payment and a bank transfer were added by the project owner on
 * 2026-10-11 and are recorded the same way: from the card terminal's or the
 * bank's reference, which RedAgos does not check with anyone.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';

    case Gcash = 'gcash';

    case Card = 'card';

    case BankTransfer = 'bank_transfer';

    /**
     * Get every accepted payment method value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown on receipts.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gcash => 'GCash',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
        };
    }

    /**
     * Determine whether settlement depends on an external provider.
     *
     * Such a payment must carry the provider's reference: it is the only
     * evidence the money moved. Cash is in the drawer.
     */
    public function isElectronic(): bool
    {
        return $this !== self::Cash;
    }
}
