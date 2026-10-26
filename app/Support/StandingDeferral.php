<?php

namespace App\Support;

use App\Enums\ScreeningOutcome;
use Illuminate\Support\Carbon;

/**
 * A permanent or indefinite deferral standing on a donor's record.
 *
 * The Donors list also uses it for a temporary deferral: the donor's latest
 * screening, or one staff recorded by hand with an end date
 * (DonorDeferralRepository::deferralsFor()); the counter's notice never
 * receives one.
 *
 * Read from three places — the counter's screening outcome, the laboratory's
 * counselling referral and the Deferrals page — and reported the same way, so
 * the counter cannot tell which one it came from. That is deliberate: "a
 * laboratory result deferred this donor" is itself a disclosure the counter
 * does not need.
 */
final class StandingDeferral
{
    public function __construct(
        public readonly ScreeningOutcome $outcome,
        public readonly ?Carbon $recordedAt,
        public readonly ?Carbon $endsOn = null,
    ) {}

    /**
     * The three keys the counter's prior-deferral notice reads. Nothing else.
     *
     * `endsOn` stays out: it is set only for a temporary deferral recorded by
     * hand, which the notice never receives. The Donors list adds it itself
     * (DonorStanding::toArray()).
     *
     * @return array{outcome: string, outcome_label: string, recorded_on: string|null}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'outcome_label' => $this->outcome->label(),
            'recorded_on' => $this->recordedAt?->toDateString(),
        ];
    }
}
