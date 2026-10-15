<?php

namespace App\Enums;

/**
 * The states a facility record can be in.
 *
 * A facility is created active (approved) by a Super Admin. A Super Admin can
 * take it out of service (inactive) and put it back; pending_approval and
 * rejected exist only for the records left behind by the removed public
 * registration flow.
 */
enum FacilityStatus: string
{
    case PendingApproval = 'pending_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    /**
     * Taken out of service by a Super Admin (project owner, 2026-10-15). Its
     * staff cannot sign in or act, donors cannot book it and hospitals cannot
     * ask it for blood. Its records stay, and it can be reactivated.
     */
    case Inactive = 'inactive';

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
            self::Inactive => 'facility_inactive',
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
            self::Inactive => 'This facility has been deactivated. Please contact the RedAgos administrator.',
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
