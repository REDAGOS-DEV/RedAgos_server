<?php

namespace App\Enums;

/**
 * Where the evidence for a confirmed payment came from.
 *
 * Manual means billing staff recorded money they took, or a transfer they
 * were shown, and stands on their word and the audit trail. Gateway means a
 * payment provider confirmed it and the server verified that confirmation.
 * The two are corrected differently: a manual payment through the approved
 * correction workflow, a gateway payment never, because its figures are the
 * provider's, not anybody's entry.
 */
enum PaymentSource: string
{
    case Manual = 'manual';

    case Gateway = 'gateway';

    /**
     * Get every accepted payment source value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in payment history.
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Recorded by staff',
            self::Gateway => 'Confirmed by payment provider',
        };
    }
}
