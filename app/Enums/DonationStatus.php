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
     * Determine whether this status means the blood may be issued to a patient.
     *
     * `completed` means transfusion-transmissible-infection testing has finished
     * and the donation is cleared for issue. `tested` is the separate, earlier
     * status. Blood-unit intake gates on exactly this distinction; see the
     * donation-status entry in docs/IMPLEMENTATION_DECISIONS.md.
     */
    public function isIssuable(): bool
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
     * Donor/Collection registers a donor and takes them through screening to
     * collection; Laboratory/Processing takes it from there to cleared stock.
     * Recorded in docs/IMPLEMENTATION_DECISIONS.md, "Who creates a donation and
     * owns its status".
     */
    public function owningDepartment(): ?Department
    {
        return match ($this) {
            self::Registered, self::Screening => Department::Collection,
            self::Collected, self::Tested => Department::Laboratory,
            self::Completed, self::Rejected => null,
        };
    }
}
