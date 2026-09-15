<?php

namespace App\Enums;

/**
 * How quickly a blood request needs to be met.
 *
 * The Capstone data dictionary declares exactly these two. The client currently
 * shows three other vocabularies — normal/urgent/emergency, Routine/Urgent/
 * Emergency and Critical/High/Standard — none of which the schema has ever
 * accepted; this enum is the one the API speaks.
 */
enum UrgencyLevel: string
{
    case Routine = 'routine';

    case Emergency = 'emergency';

    /**
     * Get every accepted urgency value, in the order the column declares them.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown on request forms and queues.
     */
    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Routine',
            self::Emergency => 'Emergency',
        };
    }

    /**
     * Determine whether requests at this level should surface above the queue.
     */
    public function isPrioritised(): bool
    {
        return $this === self::Emergency;
    }
}
