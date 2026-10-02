<?php

namespace App\Enums;

/**
 * The laboratory's overall outcome for a donation.
 *
 * Derived, not entered: the Testing department records immunohematology and
 * the five-marker serology panel, and the service rolls them up — any reactive
 * marker is `reactive`, all five non-reactive with a typing is `passed`.
 */
enum TestResult: string
{
    case Passed = 'passed';

    case Reactive = 'reactive';

    /**
     * Legacy only. Recorded before serology was itemised; never written now,
     * because each marker is a final reactive or non-reactive reading.
     */
    case Inconclusive = 'inconclusive';

    /**
     * Get every accepted result value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in the processing queue.
     */
    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Reactive => 'Reactive',
            self::Inconclusive => 'Inconclusive',
        };
    }

    /**
     * Determine whether this result permits the donation to be cleared for issue.
     *
     * Only `passed` does. A reactive or inconclusive donation can never reach
     * `completed`, which is the status blood-unit intake gates on — so this is
     * the rule that keeps untested blood out of a patient.
     */
    public function clearsForIssue(): bool
    {
        return $this === self::Passed;
    }
}
