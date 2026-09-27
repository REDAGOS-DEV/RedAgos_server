<?php

namespace App\Enums;

/**
 * The things that can happen to a blood request, as its history records them.
 */
enum RequestEventType: string
{
    case Submitted = 'submitted';

    case WalkInRecorded = 'walk_in_recorded';

    case FollowUpCreated = 'follow_up_created';

    case RemainderForwarded = 'remainder_forwarded';

    case FollowUpWithdrawn = 'follow_up_withdrawn';

    case Allocated = 'allocated';

    case HoldsReturned = 'holds_returned';

    case HoldExpired = 'hold_expired';

    case Released = 'released';

    case ReceiptConfirmed = 'receipt_confirmed';

    case LineClosed = 'line_closed';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    /**
     * Get the human-readable label shown on the request timeline.
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted through the Blood Bank Portal',
            self::WalkInRecorded => 'Walk-in request recorded after hospital verification',
            self::FollowUpCreated => 'Created as a follow-up for a remaining quantity',
            self::RemainderForwarded => 'Remaining quantity forwarded to another facility',
            self::FollowUpWithdrawn => 'Follow-up withdrawn — remainder returned to this request',
            self::Allocated => 'Units reserved',
            self::HoldsReturned => 'Reserved units returned to stock',
            self::HoldExpired => 'Reserved units expired and were returned to stock',
            self::Released => 'Units released',
            self::ReceiptConfirmed => 'Receipt confirmed by the hospital',
            self::LineClosed => 'Remaining quantity closed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }
}
