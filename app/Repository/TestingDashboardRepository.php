<?php

namespace App\Repository;

use App\Enums\ClearanceKind;
use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Enums\ReferralStatus;
use App\Enums\SerologyMarker;
use App\Models\CorrectionRequest;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationImmunohematology;
use App\Models\DonationSerology;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The counts behind the Testing dashboard, all for one facility.
 *
 * Timestamps are compared against absolute bounds (OperationalDay::boundsFor()),
 * never with whereDate(), and rows that need grouping by day are returned for
 * the service to bucket in the operational timezone, as on the Collection
 * dashboard.
 */
class TestingDashboardRepository
{
    /**
     * Every drawn donation still waiting on Testing, with what the service
     * needs to say which section it waits for.
     *
     * The same rule as the Testing page's queue (LaboratoryRepository,
     * stage=testing): not rejected, and still without one of the two
     * clearances. Completed ones count, since Processing does not wait.
     *
     * @return Collection<int, Donation>
     */
    public function waitingDonations(int $facilityId): Collection
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                DonationStatus::Collected->value,
                DonationStatus::Tested->value,
                DonationStatus::Completed->value,
            ])
            ->where(function (Builder $missing): void {
                foreach (ClearanceKind::cases() as $kind) {
                    $missing->orWhereDoesntHave(
                        'clearances',
                        fn (Builder $clearance): Builder => $clearance->where('kind', $kind->value)
                    );
                }
            })
            ->with([
                'clearances:id,donation_id,kind',
                'collection:id,donation_id,ended_at',
                'immunohematology:id,donation_id',
                'serology',
            ])
            ->get(['id', 'donation_date', 'status']);
    }

    /**
     * Serology panels recorded here within the bounds, marker by marker.
     *
     * @return Collection<int, DonationSerology>
     */
    public function panelsRecordedBetween(int $facilityId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DonationSerology::query()
            ->whereIn('donation_id', $this->facilityDonationIds($facilityId))
            ->whereBetween('recorded_at', [$from, $to])
            ->get(['id', 'donation_id', 'recorded_at', 'verified_at', ...SerologyMarker::values()]);
    }

    /**
     * How many typings were recorded (or corrected) here within the bounds.
     */
    public function typingsRecordedBetween(int $facilityId, CarbonInterface $from, CarbonInterface $to): int
    {
        return DonationImmunohematology::query()
            ->whereIn('donation_id', $this->facilityDonationIds($facilityId))
            ->whereBetween('recorded_at', [$from, $to])
            ->count();
    }

    /**
     * Donations here that were issued a clearance at the bench since `$from`,
     * with both of their live clearances and when the blood was drawn.
     *
     * A clearance with no issuer was backfilled when clearances were
     * introduced, not earned at the bench, so it says nothing about how long
     * testing took and is left out.
     *
     * @return Collection<int, Donation>
     */
    public function recentlyClearedDonations(int $facilityId, CarbonInterface $from): Collection
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereHas(
                'clearances',
                fn (Builder $clearance): Builder => $clearance->whereNotNull('issued_by')->where('issued_at', '>=', $from)
            )
            ->with([
                'clearances' => fn ($clearance) => $clearance->whereNotNull('issued_by')->select(['id', 'donation_id', 'kind', 'issued_at']),
                'collection:id,donation_id,ended_at',
            ])
            ->get(['id', 'donation_date']);
    }

    /**
     * Corrections to a typing or a serology panel waiting for Testing's
     * approver.
     */
    public function pendingTestingCorrections(int $facilityId): int
    {
        return CorrectionRequest::query()
            ->where('facility_id', $facilityId)
            ->where('status', 'pending')
            ->whereIn('subject', [CorrectionSubject::Immunohematology->value, CorrectionSubject::Serology->value])
            ->count();
    }

    /**
     * Counselling referrals here: the open ones by status, how many of those
     * still have no contact request, and how many were closed since `$from`.
     *
     * @return array{pending: int, referred: int, no_contact_request: int, closed_recently: int}
     */
    public function referralCounts(int $facilityId, CarbonInterface $from): array
    {
        $open = CounsellingReferral::query()
            ->where('facility_id', $facilityId)
            ->where('status', '!=', ReferralStatus::Closed->value)
            ->get(['id', 'status', 'contact_requested_at']);

        return [
            'pending' => $open->where('status', ReferralStatus::Pending)->count(),
            'referred' => $open->where('status', ReferralStatus::Referred)->count(),
            'no_contact_request' => $open->whereNull('contact_requested_at')->count(),
            'closed_recently' => CounsellingReferral::query()
                ->where('facility_id', $facilityId)
                ->where('status', ReferralStatus::Closed->value)
                ->where('closed_at', '>=', $from)
                ->count(),
        ];
    }

    /**
     * @return Builder<Donation>
     */
    private function facilityDonationIds(int $facilityId): Builder
    {
        return Donation::query()->select('id')->where('facility_id', $facilityId);
    }
}
