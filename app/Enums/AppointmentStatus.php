<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';

    case Confirmed = 'confirmed';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    case NoShow = 'no_show';

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
     * Get the human-readable label shown in the counter queue.
     *
     * Two labels deliberately differ from the stored value. `confirmed` is
     * written when the donor's QR is scanned (or they are checked in by hand),
     * so to staff it means "the visit is under way" rather than "the booking
     * was acknowledged"; `completed` is written when the collection is recorded,
     * which to staff reads as "collected". The client renders exactly these
     * words — see STATUS_LABELS in blood-center/appointments.vue.
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'In progress',
            self::Completed => 'Collected',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /**
     * Determine whether an appointment in this state still occupies a slot.
     *
     * This is what slot-availability and duplicate-booking checks count, so a
     * cancelled or closed appointment frees the time it was holding.
     */
    public function holdsSlot(): bool
    {
        return $this === self::Scheduled || $this === self::Confirmed;
    }

    /**
     * Determine whether the appointment has finished, by any route.
     */
    public function isTerminal(): bool
    {
        return ! $this->holdsSlot();
    }

    /**
     * Get every status that still occupies a slot.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->holdsSlot())
        ));
    }
}
