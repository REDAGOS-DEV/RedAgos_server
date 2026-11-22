<?php

namespace App\Service;

use App\Models\User;
use App\Repository\DonorDeferralRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\DonorStanding;
use Illuminate\Database\Eloquent\Builder;
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
    /** Donors worked out at a time when a list is filtered by eligibility. */
    private const CHUNK = 500;

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

    /**
     * Narrow a donor query to the donors whose eligibility is $eligibility
     * (DonorStanding::ELIGIBILITIES); no narrowing when it is null.
     *
     * Eligibility is worked out, not stored: three sources of deferral, a
     * visit open at this centre and the donation interval. So the query is
     * walked a chunk at a time, as the reminders walk it, and narrowed to the
     * ids that match. The caller keeps its own order and paging.
     *
     * @param  Builder<User>  $donors  a query over users with donorProfile eager-loaded
     * @return Builder<User>
     */
    public function narrowToEligibility(Builder $donors, int $facilityId, ?string $eligibility): Builder
    {
        if ($eligibility === null) {
            return $donors;
        }

        $ids = [];

        (clone $donors)->reorder()->chunkById(self::CHUNK, function ($chunk) use ($facilityId, $eligibility, &$ids): void {
            foreach ($this->forDonors($facilityId, $chunk) as $id => $standing) {
                if ($standing->eligibility() === $eligibility) {
                    $ids[] = $id;
                }
            }
        }, 'users.id', 'id');

        return $donors->whereIntegerInRaw('users.id', $ids);
    }
}
