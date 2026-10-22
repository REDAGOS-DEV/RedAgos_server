<?php

namespace App\Service;

use App\Enums\ClearanceKind;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationComponent;
use App\Models\Facility;
use App\Models\User;
use App\Repository\ProcessingDashboardRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * The Processing department's dashboard, for one centre (project owner,
 * 2026-10-22): what waits with Processing now, today's work, how quickly a
 * unit is separated and handed over, and the last 30 days of bags.
 *
 * The stages follow the bench since final labelling moved to Processing
 * (FinalLabelService): separate, wait for Testing to clear, print the final
 * labels, hand over. Counts only: no donor is named and no unit is listed,
 * since Processing works blind to identity. The corrections and rejections
 * go only to the component technologist (lab.update_status), who decides
 * them.
 */
class ProcessingDashboardService
{
    private const TREND_DAYS = 30;

    public function __construct(
        private readonly ProcessingDashboardRepository $processingDashboardRepository
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

        $separated = $this->separatedSince($facility, $windowStart);
        $separatedToday = $separated->filter(fn (array $unit): bool => $unit['separated_at']->between($dayStart, $dayEnd));
        $handOvers = $this->processingDashboardRepository->handOversSince($facility->id, $windowStart);

        return [
            'as_of' => OperationalDay::today()->toIso8601String(),
            'today' => [
                'date' => $today->toDateString(),
                'separated' => $separatedToday->count(),
                'bags' => $separatedToday->sum('bags'),
                'labelled' => $this->processingDashboardRepository->labelledBetween($facility->id, $dayStart, $dayEnd),
                'handed_over' => $handOvers->filter(fn (CarbonImmutable $at): bool => $at->between($dayStart, $dayEnd))->count(),
            ],
            'queue' => $this->queue($facility),
            'separation' => $this->median($separated->map(fn (array $unit): float => $this->hoursBetween($unit['drawn_at'], $unit['separated_at']))),
            'handover' => $this->handoverTurnaround($handOvers),
            'by_component' => $this->byComponent($separated),
            'trend' => $this->trend($separated, $today),
            'technologist' => $staff->can('lab.update_status')
                ? [
                    'corrections' => $this->processingDashboardRepository->pendingComponentCorrections($facility->id),
                    'rejected_recently' => $this->processingDashboardRepository->processingRejectionsSince($facility->id, $windowStart),
                ]
                : null,
        ];
    }

    /**
     * Where each unit still with Processing stands, in bench order: not yet
     * separated, separated and waiting for Testing, cleared and waiting for
     * its final labels, labelled and waiting to be handed over.
     *
     * @return array<string, mixed>
     */
    private function queue(Facility $facility): array
    {
        $open = $this->processingDashboardRepository->openDonations($facility->id);
        $queue = ['total' => $open->count(), 'to_separate' => 0, 'awaiting_testing' => 0, 'to_label' => 0, 'to_hand_over' => 0];
        $oldest = null;

        foreach ($open as $donation) {
            if (! $donation->components_exists) {
                $queue['to_separate']++;
                $drawnAt = $this->drawnAt($donation);
                $oldest = $oldest === null || $drawnAt->lt($oldest) ? $drawnAt : $oldest;

                continue;
            }

            $kinds = $donation->clearances->map(fn (DonationClearance $clearance): string => $clearance->kind->value)->unique();

            match (true) {
                $kinds->count() < count(ClearanceKind::cases()) => $queue['awaiting_testing']++,
                $donation->labelled_at === null => $queue['to_label']++,
                default => $queue['to_hand_over']++,
            };
        }

        // The oldest bag not yet separated: the one whose plasma is closest to its limit.
        return [...$queue, 'oldest_unseparated_at' => $oldest?->toIso8601String()];
    }

    /**
     * Every donation first separated since `$from`: when it was drawn, when
     * it was separated, and its bags by component.
     *
     * @return Collection<int, array{drawn_at: CarbonImmutable, separated_at: CarbonImmutable, bags: int, components: array<string, int>}>
     */
    private function separatedSince(Facility $facility, CarbonImmutable $from): Collection
    {
        $first = $this->processingDashboardRepository->firstSeparatedSince($facility->id, $from);

        if ($first->isEmpty()) {
            return collect();
        }

        return $this->processingDashboardRepository
            ->separatedDonations($first->keys()->all())
            ->map(function (Donation $donation) use ($first): array {
                $components = [];

                foreach ($donation->components as $row) {
                    /** @var DonationComponent $row */
                    $name = $row->component?->name ?? 'Component';
                    $components[$name] = ($components[$name] ?? 0) + max(1, (int) $row->quantity);
                }

                return [
                    'drawn_at' => $this->drawnAt($donation),
                    'separated_at' => $first->get($donation->id),
                    'bags' => array_sum($components),
                    'components' => $components,
                ];
            })
            ->values()
            ->toBase();
    }

    /**
     * From the moment a unit was fully cleared to its hand-over, over the
     * last 30 days: how long the bags waited for their labels and the
     * hand-over. A unit handed over before it was cleared (before final
     * labelling moved to Processing) is left out.
     *
     * @param  Collection<int, CarbonImmutable>  $handOvers
     * @return array{median_hours: float|null, units: int}
     */
    private function handoverTurnaround(Collection $handOvers): array
    {
        if ($handOvers->isEmpty()) {
            return ['median_hours' => null, 'units' => 0];
        }

        $cleared = $this->processingDashboardRepository->fullyClearedAt($handOvers->keys()->all(), count(ClearanceKind::cases()));

        $hours = $handOvers
            ->map(fn (CarbonImmutable $handedAt, int $donationId): ?float => ($clearedAt = $cleared->get($donationId)) !== null && $clearedAt->lte($handedAt)
                ? $this->hoursBetween($clearedAt, $handedAt)
                : null)
            ->filter(fn (?float $value): bool => $value !== null);

        return $this->median($hours);
    }

    /**
     * Bags separated in the last 30 days by component, most first.
     *
     * @param  Collection<int, array{components: array<string, int>}>  $separated
     * @return array<int, array{component: string, bags: int}>
     */
    private function byComponent(Collection $separated): array
    {
        $totals = [];

        foreach ($separated as $unit) {
            foreach ($unit['components'] as $name => $bags) {
                $totals[$name] = ($totals[$name] ?? 0) + $bags;
            }
        }

        arsort($totals);

        return array_map(
            fn (string $name, int $bags): array => ['component' => $name, 'bags' => $bags],
            array_keys($totals),
            array_values($totals)
        );
    }

    /**
     * Bags separated per day for the last 30 days, today included, by the day
     * each unit was first separated.
     *
     * @param  Collection<int, array{separated_at: CarbonImmutable, bags: int}>  $separated
     * @return array<int, array{date: string, bags: int, units: int}>
     */
    private function trend(Collection $separated, CarbonImmutable $today): array
    {
        $first = $today->subDays(self::TREND_DAYS - 1);
        $buckets = [];

        for ($day = $first; $day->lte($today); $day = $day->addDay()) {
            $buckets[$day->toDateString()] = ['date' => $day->toDateString(), 'bags' => 0, 'units' => 0];
        }

        foreach ($separated as $unit) {
            $date = OperationalDay::dateOf($unit['separated_at']);

            if (isset($buckets[$date])) {
                $buckets[$date]['bags'] += $unit['bags'];
                $buckets[$date]['units']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * The median of a set of spans in hours, so one unit left over a weekend
     * does not stand for all of them.
     *
     * @param  Collection<int, float>  $hours
     * @return array{median_hours: float|null, units: int}
     */
    private function median(Collection $hours): array
    {
        $sorted = $hours->sort()->values();
        $count = $sorted->count();

        if ($count === 0) {
            return ['median_hours' => null, 'units' => 0];
        }

        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

        return ['median_hours' => round($median, 1), 'units' => $count];
    }

    private function hoursBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return max(0, $from->diffInMinutes($to, false)) / 60;
    }

    /**
     * When the blood was drawn: the end of the collection, or the visit when
     * none was recorded (a paper record).
     */
    private function drawnAt(Donation $donation): CarbonImmutable
    {
        return CarbonImmutable::parse($donation->collection?->ended_at ?? $donation->donation_date);
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
