<?php

namespace App\Enums;

enum FacilityStatus: string
{
    case PendingApproval = 'pending_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Suspended = 'suspended';

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
            self::Suspended => 'facility_suspended',
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
            self::Suspended => 'This facility has been suspended. Please contact the administrator.',
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
