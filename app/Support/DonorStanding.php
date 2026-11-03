<?php

namespace App\Support;

use App\Enums\ScreeningOutcome;
use Illuminate\Support\Carbon;

/**
 * Where one donor stands with one blood centre: how often they have given,
 * when they may give again, whether a deferral stands in the way, and whether
 * they are still an active donor.
 *
 * Built by DonorStandingResolver, and read the same way by the Donors list,
 * the export and the eligibility reminders, so the pill a receptionist sees
 * is the rule the reminder obeys.
 */
final class DonorStanding
{
    public const ELIGIBLE = 'eligible';

    public const WAITING = 'waiting';

    public const DEFERRED = 'deferred';

    /**
     * A visit is open at this centre and no blood has been drawn yet: the
     * donor is registered or being screened. The counter refuses a second
     * visit, so the list must not call them eligible (project owner,
     * 2026-10-17). Once the bag is drawn they are waiting instead.
     */
    public const IN_PROGRESS = 'in_progress';

    /**
     * Gave blood within the last ACTIVE_MONTHS months. Worked out from the
     * last donation, never set by staff (project owner, 2026-11-03).
     */
    public const ACTIVE = 'active';

    /**
     * Gave blood before then, and not since.
     */
    public const INACTIVE = 'inactive';

    public const ACTIVE_MONTHS = 12;

    public function __construct(
        public readonly int $totalDonations,
        public readonly int $donationsHere,
        public readonly ?Carbon $lastDonationAt,
        public readonly bool $lastDonationDeclared,
        public readonly ?Carbon $nextEligibleDate,
        public readonly ?StandingDeferral $deferral,
        public readonly ?int $openVisitId = null,
    ) {}

    /**
     * eligible, in_progress (a visit is under way here), waiting (the
     * donation interval has not passed) or deferred.
     */
    public function eligibility(): string
    {
        return match (true) {
            $this->deferral !== null => self::DEFERRED,
            $this->openVisitId !== null => self::IN_PROGRESS,
            $this->nextEligibleDate !== null && $this->nextEligibleDate->isAfter(today()) => self::WAITING,
            default => self::ELIGIBLE,
        };
    }

    public function isEligibleNow(): bool
    {
        return $this->eligibility() === self::ELIGIBLE;
    }

    /**
     * active or inactive, from the same last donation the list shows (a
     * declared one included); null when there is none.
     */
    public function activity(): ?string
    {
        return match (true) {
            $this->lastDonationAt === null => null,
            $this->lastDonationAt->isBefore(self::activeSince()) => self::INACTIVE,
            default => self::ACTIVE,
        };
    }

    /**
     * The earliest last donation that still counts as active.
     */
    public static function activeSince(): Carbon
    {
        return today()->subMonthsNoOverflow(self::ACTIVE_MONTHS);
    }

    /**
     * A deferral that has no end: permanent or indefinite.
     */
    public function hasStandingDeferral(): bool
    {
        return $this->deferral !== null && $this->deferral->outcome !== ScreeningOutcome::TemporarilyDeferred;
    }

    /**
     * permanent, indefinite or temporary; null when not deferred.
     */
    public function deferralKind(): ?string
    {
        return match ($this->deferral?->outcome) {
            ScreeningOutcome::PermanentlyDeferred => 'permanent',
            ScreeningOutcome::IndefiniteDeferral => 'indefinite',
            ScreeningOutcome::TemporarilyDeferred => 'temporary',
            default => null,
        };
    }

    /**
     * The keys every Donors-list shape carries.
     *
     * The deferral's kind and date go only to a holder of donors.view; the
     * contact list learns that the donor is deferred and nothing more, which
     * is enough not to call them in.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withDeferral): array
    {
        return [
            'total_donations' => $this->totalDonations,
            'donations_here' => $this->donationsHere,
            'last_donation_at' => $this->lastDonationAt?->toDateString(),
            'last_donation_declared' => $this->lastDonationDeclared,
            'next_eligible_date' => $this->nextEligibleDate?->toDateString(),
            'eligibility' => $this->eligibility(),
            'status' => $this->activity(),
            'open_visit_id' => $this->openVisitId,
            'deferral' => $withDeferral && $this->deferral !== null
                ? [
                    ...$this->deferral->toArray(),
                    'kind' => $this->deferralKind(),
                    // A temporary deferral recorded by hand says when it ends;
                    // one from a screening holds until the next screening.
                    'ends_on' => $this->deferral->endsOn?->toDateString(),
                ]
                : null,
        ];
    }
}
