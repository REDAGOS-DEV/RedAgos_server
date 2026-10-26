<?php

namespace App\Service;

use App\Enums\ClearanceKind;
use App\Enums\SerologyMarker;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationSerology;
use App\Models\Facility;
use App\Models\User;
use App\Repository\TestingDashboardRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * The Testing department's dashboard, for one centre (project owner,
 * 2026-10-22): what waits at the bench now, today's work, how long a unit
 * takes to clear, and the last 30 days of serology panels.
 *
 * Counts only: no donor is named and no unit is listed. The serology
 * technologists who read it work blind to identity, so nothing here may tie a
 * reading to a person. The referral counts go only to whoever works the
 * referral list (lab.referrals), and the approval counts only to the
 * Laboratory Supervisor (lab.verify_serology).
 */
class TestingDashboardService
{
    private const TREND_DAYS = 30;

    public function __construct(
        private readonly TestingDashboardRepository $testingDashboardRepository
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $staff): array
    {
        $facility = $this->requireFacility($staff);
        $today = OperationalDay::today()->startOfDay();
        [$dayStart, $dayEnd] = OperationalDay::boundsFor();
        [$windowStart] = OperationalDay::boundsFor($today->subDays(self::TREND_DAYS - 1)->toDateString());

        $panels = $this->testingDashboardRepository->panelsRecordedBetween($facility->id, $windowStart, $dayEnd);
        $panelsToday = $panels->filter(fn (DonationSerology $panel): bool => $panel->recorded_at->between($dayStart, $dayEnd));
        $cleared = $this->fullyClearedSince($facility, $windowStart);

        return [
            'as_of' => OperationalDay::today()->toIso8601String(),
            'today' => [
                'date' => $today->toDateString(),
                'typings' => $this->testingDashboardRepository->typingsRecordedBetween($facility->id, $dayStart, $dayEnd),
                'panels' => $panelsToday->count(),
                'reactive' => $panelsToday->filter(fn (DonationSerology $panel): bool => $panel->isReactive())->count(),
                'cleared' => $cleared->filter(fn (array $unit): bool => $unit['cleared_at']->between($dayStart, $dayEnd))->count(),
            ],
            'queue' => $this->queue($facility),
            'turnaround' => $this->turnaround($cleared),
            'reactive_by_marker' => $this->reactiveByMarker($panels),
            'trend' => $this->trend($panels, $today),
            'approvals' => $staff->can('lab.verify_serology')
                ? ['corrections' => $this->testingDashboardRepository->pendingTestingCorrections($facility->id)]
                : null,
            'referrals' => $staff->can('lab.referrals')
                ? $this->testingDashboardRepository->referralCounts($facility->id, $windowStart)
                : null,
        ];
    }

    /**
     * What waits at the bench, by what it waits for. A unit waiting for both
     * sections is counted under each, and once in the total.
     *
     * @return array<string, mixed>
     */
    private function queue(Facility $facility): array
    {
        $waiting = $this->testingDashboardRepository->waitingDonations($facility->id);
        $queue = ['total' => $waiting->count(), 'awaiting_typing' => 0, 'typing_held' => 0, 'awaiting_serology' => 0, 'awaiting_verification' => 0];
        $oldest = null;

        foreach ($waiting as $donation) {
            $kinds = $donation->clearances->map(fn (DonationClearance $clearance): string => $clearance->kind->value);

            if (! $kinds->contains(ClearanceKind::Immunohematology->value)) {
                // Typed but held (a discrepancy or a positive screen), or not typed yet.
                $donation->immunohematology ? $queue['typing_held']++ : $queue['awaiting_typing']++;
            }

            if (! $kinds->contains(ClearanceKind::Tti->value)) {
                if ($donation->serology?->isAwaitingVerification()) {
                    $queue['awaiting_verification']++;
                } elseif (! $donation->serology) {
                    $queue['awaiting_serology']++;
                }
            }

            $drawnAt = $this->drawnAt($donation);
            $oldest = $oldest === null || $drawnAt->lt($oldest) ? $drawnAt : $oldest;
        }

        return [...$queue, 'oldest_drawn_at' => $oldest?->toIso8601String()];
    }

    /**
     * Donations whose second clearance was issued at the bench since `$from`,
     * with when the blood was drawn and when the unit was fully cleared.
     *
     * @return Collection<int, array{drawn_at: CarbonImmutable, cleared_at: CarbonImmutable}>
     */
    private function fullyClearedSince(Facility $facility, CarbonImmutable $from): Collection
    {
        return $this->testingDashboardRepository
            ->recentlyClearedDonations($facility->id, $from)
            ->map(function (Donation $donation): ?array {
                $issued = $donation->clearances
                    ->mapWithKeys(fn (DonationClearance $clearance): array => [
                        $clearance->kind->value => CarbonImmutable::parse($clearance->issued_at),
                    ]);

                if ($issued->count() < count(ClearanceKind::cases())) {
                    return null;
                }

                return ['drawn_at' => $this->drawnAt($donation), 'cleared_at' => $issued->max()];
            })
            ->filter(fn (?array $unit): bool => $unit !== null && $unit['cleared_at']->gte($from))
            ->values()
            ->toBase();
    }

    /**
     * How long a unit took from the draw to its second clearance, over the
     * last 30 days: the median, so one unit left over a weekend does not
     * stand for all of them.
     *
     * @param  Collection<int, array{drawn_at: CarbonImmutable, cleared_at: CarbonImmutable}>  $cleared
     * @return array{median_hours: float|null, units: int}
     */
    private function turnaround(Collection $cleared): array
    {
        $hours = $cleared
            ->map(fn (array $unit): float => max(0, $unit['drawn_at']->diffInMinutes($unit['cleared_at'], false)) / 60)
            ->sort()
            ->values();

        $count = $hours->count();

        if ($count === 0) {
            return ['median_hours' => null, 'units' => 0];
        }

        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $hours[$middle] : ($hours[$middle - 1] + $hours[$middle]) / 2;

        return ['median_hours' => round($median, 1), 'units' => $count];
    }

    /**
     * How many panels in the last 30 days were reactive for each marker, in
     * the form's order. A panel reactive for two markers counts under both.
     *
     * @param  Collection<int, DonationSerology>  $panels
     * @return array<int, array{marker: string, label: string, count: int}>
     */
    private function reactiveByMarker(Collection $panels): array
    {
        return array_map(
            fn (SerologyMarker $marker): array => [
                'marker' => $marker->value,
                'label' => $marker->label(),
                'count' => $panels->filter(fn (DonationSerology $panel): bool => $panel->resultFor($marker)?->isReactive() ?? false)->count(),
            ],
            SerologyMarker::cases()
        );
    }

    /**
     * Serology panels recorded per day for the last 30 days, today included,
     * split by outcome. A reactive panel still waiting for verification counts
     * as reactive: that is what was read at the bench.
     *
     * @param  Collection<int, DonationSerology>  $panels
     * @return array<int, array{date: string, non_reactive: int, reactive: int}>
     */
    private function trend(Collection $panels, CarbonImmutable $today): array
    {
        $first = $today->subDays(self::TREND_DAYS - 1);
        $buckets = [];

        for ($day = $first; $day->lte($today); $day = $day->addDay()) {
            $buckets[$day->toDateString()] = ['date' => $day->toDateString(), 'non_reactive' => 0, 'reactive' => 0];
        }

        foreach ($panels as $panel) {
            $date = $this->operationalDate($panel->recorded_at);

            if (isset($buckets[$date])) {
                $buckets[$date][$panel->isReactive() ? 'reactive' : 'non_reactive']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * When the blood was drawn: the end of the collection, or the visit when
     * none was recorded (a paper record).
     */
    private function drawnAt(Donation $donation): CarbonImmutable
    {
        return CarbonImmutable::parse($donation->collection?->ended_at ?? $donation->donation_date);
    }

    /**
     * The operational calendar date of a stored instant.
     */
    private function operationalDate(mixed $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone(OperationalDay::today()->timezone)->toDateString();
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));
    }
}
