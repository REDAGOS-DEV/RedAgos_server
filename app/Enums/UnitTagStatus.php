<?php

namespace App\Enums;

/**
 * The states one patient's hold on one hospital bag moves through.
 *
 * Tag Assigned: a specific bag is reserved for a specific patient, still in
 * storage, with 24 hours for crossmatching. Tag Crossmatched: the same bag has
 * been crossmatched for that patient and taken out of storage, with 24 hours
 * for the transfusion. Either can end in its Untagged counterpart — when the
 * period runs out, or when staff release it — and the tag row stays as the
 * record that it happened.
 */
enum UnitTagStatus: string
{
    case TagAssigned = 'tag_assigned';

    case TagCrossmatched = 'tag_crossmatched';

    case Transfused = 'transfused';

    case UntaggedAssigned = 'untagged_assigned';

    case UntaggedCrossmatched = 'untagged_crossmatched';

    /**
     * Get every accepted tag status value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown on the bag.
     */
    public function label(): string
    {
        return match ($this) {
            self::TagAssigned => 'Tag Assigned',
            self::TagCrossmatched => 'Tag Crossmatched',
            self::Transfused => 'Transfused',
            self::UntaggedAssigned => 'Untagged Assigned',
            self::UntaggedCrossmatched => 'Untagged Crossmatched',
        };
    }

    /**
     * Get the line that tells staff what the bag is waiting for, or how its tag ended.
     */
    public function description(): string
    {
        return match ($this) {
            self::TagAssigned => 'Crossmatch pending',
            self::TagCrossmatched => 'Awaiting transfusion',
            self::Transfused => 'Transfused to the patient',
            self::UntaggedAssigned => 'Released before crossmatch',
            self::UntaggedCrossmatched => 'Released before transfusion',
        };
    }

    /**
     * Determine whether this status still lays claim to its bag.
     *
     * A claiming tag is one that must stop any other patient taking the same
     * bag. The partial unique index on unit_tags is built over exactly these.
     */
    public function claimsUnit(): bool
    {
        return match ($this) {
            self::TagAssigned, self::TagCrossmatched => true,
            self::Transfused, self::UntaggedAssigned, self::UntaggedCrossmatched => false,
        };
    }

    /**
     * Get every status that lays claim to its bag.
     *
     * @return array<int, string>
     */
    public static function claimingValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->claimsUnit())
        ));
    }

    /**
     * Get the status an active tag ends in when it is untagged.
     */
    public function untaggedState(): ?self
    {
        return match ($this) {
            self::TagAssigned => self::UntaggedAssigned,
            self::TagCrossmatched => self::UntaggedCrossmatched,
            default => null,
        };
    }

    /**
     * Get the statuses this one may move to.
     *
     * @return array<int, self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::TagAssigned => [self::TagCrossmatched, self::UntaggedAssigned],
            self::TagCrossmatched => [self::Transfused, self::UntaggedCrossmatched],
            self::Transfused, self::UntaggedAssigned, self::UntaggedCrossmatched => [],
        };
    }

    /**
     * Determine whether a tag in this status may move to the given one.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }
}
