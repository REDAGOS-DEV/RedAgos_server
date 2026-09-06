<?php

namespace App\Enums;

/**
 * The states a facility record can be in.
 *
 * There is no suspended state. A facility is created active by a Super Admin
 * and stays that way; pending_approval and rejected exist only for the records
 * left behind by the removed public registration flow.
 */
enum FacilityStatus: string
{
    case PendingApproval = 'pending_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

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
