<?php

namespace App\Enums;

/**
 * The final reading of one serology marker.
 *
 * Two values only. Repeat testing of an initially reactive sample happens at
 * the bench before anything is entered, so what is recorded is the medical
 * technologist's final call — there is no "inconclusive" per marker.
 */
enum MarkerResult: string
{
    case Reactive = 'reactive';

    case NonReactive = 'non_reactive';

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
     * Get the human-readable label shown to the Testing department.
     */
    public function label(): string
    {
        return match ($this) {
            self::Reactive => 'Reactive',
            self::NonReactive => 'Non-reactive',
        };
    }

    public function isReactive(): bool
    {
        return $this === self::Reactive;
    }
}
