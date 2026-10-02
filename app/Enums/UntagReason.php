<?php

namespace App\Enums;

/**
 * Why a patient's tag on a hospital bag ended without a transfusion.
 *
 * The two deadline reasons are the scheduler's; the third is a staff member's
 * decision — an incompatible crossmatch, a discharged patient, a cancelled
 * order — and always carries their note.
 */
enum UntagReason: string
{
    case CrossmatchDeadlineExpired = 'crossmatch_deadline_expired';

    case TransfusionDeadlineExpired = 'transfusion_deadline_expired';

    case ReleasedByStaff = 'released_by_staff';

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
            self::CrossmatchDeadlineExpired => 'Crossmatch deadline expired',
            self::TransfusionDeadlineExpired => 'Transfusion deadline expired',
            self::ReleasedByStaff => 'Released by staff',
        };
    }

    /**
     * Get the reason the scheduler records when an active tag's period runs out.
     */
    public static function deadlineFor(UnitTagStatus $status): ?self
    {
        return match ($status) {
            UnitTagStatus::TagAssigned => self::CrossmatchDeadlineExpired,
            UnitTagStatus::TagCrossmatched => self::TransfusionDeadlineExpired,
            default => null,
        };
    }
}
