<?php

namespace App\Enums;

/**
 * How far the Testing department has got with a reactive donor's referral.
 *
 * Testing refers the donor to a doctor and does nothing more (project owner,
 * 2026-10-26): pending, referred to a doctor, closed. Forward only. A
 * referral may skip a step — a donor who cannot be reached is closed straight
 * from pending — but never goes back, and `closed` is final. Closing ends the
 * follow-up; it does not lift the donor's deferral.
 *
 * There was a "contacted" step between pending and referred; its rows went
 * back to pending (2026_10_26_000001). The mail icon's contact request is
 * kept, and stamped on the referral, apart from its status.
 */
enum ReferralStatus: string
{
    case Pending = 'pending';

    case Referred = 'referred';

    case Closed = 'closed';

    /**
     * Get every accepted status value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown on the referral list.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Referred => 'Referred to doctor',
            self::Closed => 'Closed',
        };
    }

    /**
     * Determine whether a referral in this status may move to another.
     */
    public function canMoveTo(self $to): bool
    {
        return $this !== self::Closed && $to->rank() > $this->rank();
    }

    /**
     * Determine whether the follow-up still has work outstanding.
     */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * The timestamp column stamped when a referral reaches this status.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Pending => null,
            self::Referred => 'referred_at',
            self::Closed => 'closed_at',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Referred => 1,
            self::Closed => 2,
        };
    }
}
