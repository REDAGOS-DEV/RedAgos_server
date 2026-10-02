<?php

namespace App\Enums;

/**
 * The REMARKS block of Section I-D, as the DOH form prints it.
 *
 * Four mutually exclusive boxes: accepted, or one of three deferrals that
 * differ only in how long they last. The donation workflow treats all three
 * deferrals identically — what separates them is what the donor is told and
 * what the next counter sees.
 *
 * SCOPE BOUNDARY: nothing in RedAgos computes this. It is the screening
 * officer's verdict, transcribed. See the clinical-configuration boundary in
 * docs/IMPLEMENTATION_DECISIONS.md.
 */
enum ScreeningOutcome: string
{
    case Accepted = 'accepted';

    /**
     * The donor may return once whatever caused the deferral has passed.
     */
    case TemporarilyDeferred = 'temporarily_deferred';

    /**
     * The donor may never donate again.
     */
    case PermanentlyDeferred = 'permanently_deferred';

    /**
     * Deferred with no end date, pending something the centre is waiting on.
     */
    case IndefiniteDeferral = 'indefinite_deferral';

    /**
     * Get every accepted outcome value.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown in the counter workflow.
     *
     * Exhaustive on purpose, with no `default`: a case added without a label
     * should fail loudly at the first screening rather than quietly print a
     * raw enum value on a clinical record.
     */
    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::TemporarilyDeferred => 'Temporarily Deferred',
            self::PermanentlyDeferred => 'Permanently Deferred',
            self::IndefiniteDeferral => 'Indefinite Deferral',
        };
    }

    /**
     * Determine whether this outcome permits the donation to proceed to collection.
     */
    public function permitsCollection(): bool
    {
        return $this === self::Accepted;
    }

    /**
     * Determine whether this outcome ends the donor's visit.
     *
     * Exists so no caller has to enumerate the three deferral cases itself —
     * one that did would silently miss a fourth if the form ever grows one.
     */
    public function isDeferral(): bool
    {
        return ! $this->permitsCollection();
    }

    /**
     * Determine whether this deferral has no expected end.
     *
     * Blocks nothing: RedAgos records the officer's decision and shows it to
     * the next counter, which decides for itself. What this changes is what the
     * donor is told — a donor who may never donate again must not be sent an
     * email inviting them to book again.
     */
    public function isBlocking(): bool
    {
        return $this === self::PermanentlyDeferred
            || $this === self::IndefiniteDeferral;
    }

    /**
     * Get every outcome as a value/label pair, for the counter's picker.
     *
     * @return array<int, array<string, string>>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $outcome): array => [
                'value' => $outcome->value,
                'label' => $outcome->label(),
            ],
            self::cases()
        );
    }
}
