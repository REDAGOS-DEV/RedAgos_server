<?php

namespace App\Enums;

/**
 * Where a bag in a hospital blood bank's custody stands.
 *
 * This is the hospital's side of the bag. The blood centre that dispatched it
 * still reads it as `issued` on blood_units, and nothing here changes that.
 *
 * Tag Assigned and Tag Crossmatched mirror the active tag on the bag (see
 * UnitTagStatus): the first keeps the bag in storage, held for one patient
 * while crossmatching is done; the second means it has left storage for that
 * patient. Pending Return is what a crossmatched bag becomes when its tag ends
 * without a transfusion — it is out of the fridge, so it is not stock again
 * until staff confirm it is back, or discard it.
 */
enum HospitalUnitStatus: string
{
    case Available = 'available';

    case TagAssigned = 'tag_assigned';

    case TagCrossmatched = 'tag_crossmatched';

    case PendingReturn = 'pending_return';

    case Transfused = 'transfused';

    case Expired = 'expired';

    case Discarded = 'discarded';

    /**
     * Get every accepted status value, in the order the column declares them.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in the hospital inventory.
     */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::TagAssigned => 'Tag Assigned',
            self::TagCrossmatched => 'Tag Crossmatched',
            self::PendingReturn => 'Pending Return',
            self::Transfused => 'Transfused',
            self::Expired => 'Expired',
            self::Discarded => 'Discarded',
        };
    }

    /**
     * Get the statuses this one may move to.
     *
     * The unit's half of the lifecycle. Leaving a tagged state for Available
     * or Pending Return is the tag ending, which the tag row records as
     * Untagged Assigned or Untagged Crossmatched.
     *
     * @return array<int, self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Available => [self::TagAssigned, self::Expired, self::Discarded],
            self::TagAssigned => [self::TagCrossmatched, self::Available],
            self::TagCrossmatched => [self::Transfused, self::PendingReturn],
            self::PendingReturn => [self::Available, self::Discarded],
            self::Expired => [self::Discarded],
            self::Transfused, self::Discarded => [],
        };
    }

    /**
     * Determine whether a unit in this status may move to the given one.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    /**
     * Determine whether a bag in this status may be discarded.
     *
     * A tagged bag is promised to a patient and must be released first; a
     * transfused one is gone.
     */
    public function isDiscardable(): bool
    {
        return $this->canTransitionTo(self::Discarded);
    }
}
