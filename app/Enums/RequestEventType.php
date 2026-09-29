<?php

namespace App\Enums;

/**
 * The things that can happen to a blood request, as its history records them.
 *
 * Most happen to one facility allocation. The requirement-level ones —
 * TransfusionCreated, AllocationsAdded, RequirementClosed,
 * TransfusionCancelled — happen to a patient's requirement as a whole and name
 * no single allocation.
 */
enum RequestEventType: string
{
    case Submitted = 'submitted';

    case WalkInRecorded = 'walk_in_recorded';

    case TransfusionCreated = 'transfusion_created';

    case AllocationsAdded = 'allocations_added';

    case AllocationWithdrawn = 'allocation_withdrawn';

    case RequirementClosed = 'requirement_closed';

    case TransfusionCancelled = 'transfusion_cancelled';

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
            self::Submitted => 'Sent to the facility',
            self::WalkInRecorded => 'Walk-in recorded after hospital verification',
            self::TransfusionCreated => 'Patient Transfusion Request created',
            self::AllocationsAdded => 'Remaining units allocated to more facilities',
            self::AllocationWithdrawn => 'Allocation withdrawn by the hospital',
            self::RequirementClosed => 'Remaining quantity closed — no longer needed',
            self::TransfusionCancelled => 'Patient Transfusion Request cancelled',
            self::Allocated => 'Approved — units reserved',
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
