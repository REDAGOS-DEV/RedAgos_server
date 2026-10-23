<?php

namespace App\Service;

use App\Enums\ScreeningOutcome;
use App\Models\User;
use App\Repository\DonorDeferralRepository;

/**
 * What the donor portal may tell a donor about their own deferral.
 *
 * Read through DonorDeferralRepository::deferralsFor(), the same reading the
 * blood centre's Donors list uses, so the donor and the staff never see two
 * different answers: a standing deferral (screening, laboratory or recorded by
 * hand), failing that a hand-recorded temporary one until its end date,
 * failing that a temporary outcome at the donor's latest screening.
 *
 * The answer is deliberately thin: that a deferral stands, whether it is the
 * temporary kind, until when if it has an end, and since when. Never the
 * reason, never permanent against indefinite, and never whether a laboratory
 * result is behind it. Those are for the centre to explain in person, the line
 * the DonorDeferred email already draws; a reactive result in particular is
 * told through counselling, not a banner in an app.
 *
 * Read-only, and it blocks nothing: a deferred donor can still book and be
 * scanned (see PriorDeferralTest). It only lets the portal stop inviting them.
 */
class DonorDeferralStatusService
{
    public function __construct(
        private readonly DonorDeferralRepository $donorDeferralRepository
    ) {}

    /**
     * @return array{deferred: bool, temporary: bool|null, until: string|null, since: string|null}
     */
    public function forDonor(User $donor): array
    {
        $deferral = $this->donorDeferralRepository->deferralsFor([$donor->id])[$donor->id] ?? null;

        if ($deferral === null) {
            return ['deferred' => false, 'temporary' => null, 'until' => null, 'since' => null];
        }

        return [
            'deferred' => true,
            'temporary' => $deferral->outcome === ScreeningOutcome::TemporarilyDeferred,
            'until' => $deferral->endsOn?->toDateString(),
            'since' => $deferral->recordedAt?->toDateString(),
        ];
    }
}
