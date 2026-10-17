<?php

namespace App\Service;

use App\Models\User;
use App\Repository\DonorDeferralRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\DonorStanding;
use Illuminate\Support\Carbon;

/**
 * Works out DonorStanding for a page of donors in a fixed number of queries.
 *
 * The last donation is the last time blood was drawn, anywhere, falling back
 * to the date the donor declared (registration asks for it) when RedAgos has
 * none on record — the same reading the donor portal and the counter's
 * interval check make (EligibilityRuleEvaluator), so the three cannot
 * disagree about when someone may give again.
 */
class DonorStandingResolver
{
    public function __construct(
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly DonorDeferralRepository $donorDeferralRepository,
        private readonly EligibilityRuleEvaluator $eligibilityRuleEvaluator
    ) {}

    /**
     * @param  iterable<int, User>  $donors  with donorProfile loaded
     * @return array<int, DonorStanding> keyed by donor id
     */
    public function forDonors(int $facilityId, iterable $donors): array
    {
        $byId = [];

        foreach ($donors as $donor) {
            $byId[$donor->id] = $donor;
        }

        $ids = array_keys($byId);
        $summaries = $this->donorDirectoryRepository->summariesFor($facilityId, $ids);
        $deferrals = $this->donorDeferralRepository->deferralsFor($ids);
        $openVisits = $this->donorDirectoryRepository->openVisitsBeforeDraw($facilityId, $ids);

        $standings = [];

        foreach ($byId as $id => $donor) {
            $summary = $summaries[$id];
            $drawnAt = $summary['last_drawn_at'] !== null ? Carbon::parse($summary['last_drawn_at']) : null;
            $declared = $donor->donorProfile?->last_donation_date;
            $last = $drawnAt ?? $declared;

            $standings[$id] = new DonorStanding(
                totalDonations: $summary['total_donations'],
                donationsHere: $summary['donations_here'],
                lastDonationAt: $last,
                lastDonationDeclared: $drawnAt === null && $declared !== null,
                nextEligibleDate: $this->eligibilityRuleEvaluator->nextEligibleDate($last),
                deferral: $deferrals[$id] ?? null,
                openVisitId: $openVisits[$id] ?? null,
            );
        }

        return $standings;
    }

    public function forDonor(int $facilityId, User $donor): DonorStanding
    {
        return $this->forDonors($facilityId, [$donor])[$donor->id];
    }
}
