<?php

namespace App\Enums;

enum DonationStatus: string
{
    case Registered = 'registered';

    case Screening = 'screening';

    case Collected = 'collected';

    case Tested = 'tested';

    case Completed = 'completed';

    case Rejected = 'rejected';

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
     * Get the human-readable label shown in collection and processing queues.
     */
    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Screening => 'Screening',
            self::Collected => 'Collected',
            self::Tested => 'Tested',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Determine whether inventory may book this donation's bags in.
     *
     * `completed` means Processing has finished: the components are declared
     * and the bags go to Issuance. It no longer means "cleared for issue" —
     * that moved to the unit, which is booked in quarantined and released only
     * on both clearance tokens. See "Quarantine lifecycle" in
     * docs/IMPLEMENTATION_DECISIONS.md.
     */
    public function acceptsIntake(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Determine whether the donation has finished, either way.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Rejected;
    }

    /**
     * Get the department that owns transitions out of this status.
     *
     * Collection registers a donor and takes them through screening to
     * collection; Testing records the result that makes it `tested`; Processing
     * takes it from there to cleared stock or rejects it. Recorded in
     * docs/IMPLEMENTATION_DECISIONS.md, "Who creates a donation and owns its
     * status".
     */
    public function owningDepartment(): ?Department
    {
        return match ($this) {
            self::Registered, self::Screening => Department::Collection,
            self::Collected => Department::Testing,
            self::Tested => Department::Processing,
            self::Completed, self::Rejected => null,
        };
    }
}
