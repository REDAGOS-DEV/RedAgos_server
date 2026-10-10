<?php

namespace App\Enums;

/**
 * Where one gateway checkout stands.
 *
 * Creating: the row exists and the provider has not answered yet. Active: the
 * hosted checkout is open. AwaitingVerification: the provider said it was paid,
 * or could not be reached, and the server has not yet confirmed it by
 * re-fetching the session. Completed: confirmed, and its payment recorded.
 * Expired, Canceled, Failed and Superseded end an attempt that collected
 * nothing — Superseded because the statement changed while it was open.
 *
 * Completed is absorbing: no later event moves an attempt out of it.
 */
enum PaymentAttemptStatus: string
{
    case Creating = 'creating';

    case Active = 'active';

    case AwaitingVerification = 'awaiting_verification';

    case Completed = 'completed';

    case Expired = 'expired';

    case Canceled = 'canceled';

    case Failed = 'failed';

    case Superseded = 'superseded';

    /**
     * Get every accepted attempt status value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The statuses that keep an attempt open, and so block cash and a second checkout.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Creating, self::Active, self::AwaitingVerification];
    }

    /**
     * Get the human-readable label shown to billing staff.
     */
    public function label(): string
    {
        return match ($this) {
            self::Creating => 'Opening checkout',
            self::Active => 'Waiting for payment',
            self::AwaitingVerification => 'Confirming with provider',
            self::Completed => 'Paid',
            self::Expired => 'Expired',
            self::Canceled => 'Cancelled',
            self::Failed => 'Failed',
            self::Superseded => 'Superseded',
        };
    }

    /**
     * Determine whether the attempt still holds the statement open.
     */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
