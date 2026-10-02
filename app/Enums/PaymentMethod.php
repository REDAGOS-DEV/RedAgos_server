<?php

namespace App\Enums;

/**
 * How a blood service fee was settled.
 *
 * Cash is confirmed by billing staff at the counter; GCash is the paper's
 * named electronic method. No payment gateway is configured yet, so a GCash
 * payment is currently recorded from its reference number rather than verified
 * against a provider.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';

    case Gcash = 'gcash';

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
        };
    }

    /**
     * Determine whether settlement depends on an external provider.
     */
    public function isElectronic(): bool
    {
        return $this === self::Gcash;
    }
}
