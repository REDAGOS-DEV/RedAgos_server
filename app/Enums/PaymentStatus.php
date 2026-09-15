<?php

namespace App\Enums;

/**
 * The outcome of one payment attempt against a statement.
 *
 * The Capstone's payment table has no status column at all; these states exist
 * because an electronic payment can fail or be reversed, and a trail that only
 * records successes cannot explain why a statement is still unpaid.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';

    case Completed = 'completed';

    case Failed = 'failed';

    case Refunded = 'refunded';

    /**
     * Get every accepted payment status value.
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
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Determine whether this payment counts toward what a statement has collected.
     */
    public function isCollected(): bool
    {
        return $this === self::Completed;
    }
}
