<?php

namespace App\Enums;

/**
 * Where a billing transaction came in.
 *
 * The counter is billing staff at the centre — cash, or a GCash reference they
 * were shown. The gateway is a Xendit checkout the server verified. Outside is
 * a weekly bill the hospital settled outside RedAgos, recorded here. System is
 * the bill itself moving: raised or re-priced as units are reserved, or
 * waived by the subsidy.
 */
enum TransactionChannel: string
{
    case Counter = 'counter';

    case Gateway = 'gateway';

    case Outside = 'outside';

    case System = 'system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Counter => 'Counter',
            self::Gateway => 'GCash checkout',
            self::Outside => 'Outside RedAgos',
            self::System => 'System',
        };
    }
}
