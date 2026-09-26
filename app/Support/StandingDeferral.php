<?php

namespace App\Support;

use App\Enums\ScreeningOutcome;
use Illuminate\Support\Carbon;

/**
 * A permanent or indefinite deferral standing on a donor's record.
 *
 * Read from two places — the counter's screening outcome and the laboratory's
 * counselling referral — and reported the same way, so the counter cannot
 * tell which one it came from. That is deliberate: "a laboratory result
 * deferred this donor" is itself a disclosure the counter does not need.
 */
final class StandingDeferral
{
    public function __construct(
        public readonly ScreeningOutcome $outcome,
        public readonly ?Carbon $recordedAt,
    ) {}

    /**
     * The three keys the counter's prior-deferral notice reads. Nothing else.
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
