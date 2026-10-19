<?php

namespace App\Enums;

/**
 * The states a facility record can be in.
 *
 * There is no suspended state. A facility is created active by a Super Admin
 * and stays that way; pending_approval and rejected exist only for the records
 * left behind by the removed public registration flow.
 *
 * unregistered is a hospital a blood centre named on a walk-in when it is not
 * on RedAgos (project owner, 2026-10-19): a name and nothing else, with no
 * accounts, never listed with real facilities, and upgraded in place when an
 * administrator later creates that hospital.
 */
enum FacilityStatus: string
{
    case PendingApproval = 'pending_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    /**
     * A hospital known only by the name a walk-in gave it. Nobody signs in for it.
     */
    case Unregistered = 'unregistered';

    /**
     * Determine whether a facility in this state may act on real data.
     */
    public function canOperate(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Get the client-facing error code explaining why operation was refused.
     */
    public function blockedCode(): ?string
    {
        return match ($this) {
            self::Approved => null,
            self::PendingApproval, self::Rejected => 'facility_not_approved',
            self::Unregistered => 'facility_unregistered',
        };
    }

    /**
     * Get the human-readable message explaining why operation was refused.
     */
    public function blockedMessage(): ?string
    {
        return match ($this) {
            self::Approved => null,
            self::PendingApproval => 'This facility is still awaiting administrator approval.',
            self::Rejected => 'This facility registration was not approved.',
            self::Unregistered => 'This hospital is not registered in RedAgos.',
        };
    }

    /**
     * Get every status value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
