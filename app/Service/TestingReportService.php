<?php

namespace App\Service;

use App\Enums\ClearanceKind;
use App\Enums\DonationType;
use App\Enums\SerologyMarker;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationImmunohematology;
use App\Models\DonationSerology;
use App\Models\User;
use App\Repository\TestingDashboardRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * The Testing department's reports, for a period (project owner, 2026-10-24):
 * what the bench recorded, the same data the Testing pages hold, counted the
 * way the Collection report counts its own.
 *
 *   - the serology panels: tested, reactive and the rate, marker by marker,
 *     month by month;
 *   - who was tested and who was reactive, by sex, age bracket and type of
 *     donor (walk-in, TS, NTS, mobile drive), as on the collection record;
 *   - the typings, by blood group; how long a unit took to clear; and the
 *     counselling referrals opened, moved on and closed.
 *
 * Counts only: no donor is named and no unit is listed, since the serology
 * technologists who read it work blind to identity. A reactive panel still
 * waiting for verification counts as reactive, as on the dashboard: that is
 * what was read at the bench.
 */
class TestingReportService
{
    public const MAX_DAYS = 366;

    /** The collection record's age brackets (CollectionReportService). */
    private const AGE_BRACKETS = ['16-17' => [16, 17], '18-24' => [18, 24], '25-44' => [25, 44], '45-64' => [45, 64], '65+' => [65, 200]];

    private const SEX_LABELS = ['female' => 'Female', 'male' => 'Male', 'not_stated' => 'Not stated'];

    private const KIND_LABELS = ['walk_in' => 'Walk-in', 'ts' => 'TS', 'nts' => 'NTS', 'drive' => 'Mobile drive'];

    public function __construct(
        private readonly TestingDashboardRepository $testingDashboardRepository
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(User $staff, string $fromDate, string $toDate): array
    {
        if (! $staff->can('lab.record_serology') && ! $staff->can('lab.record_immunohematology')) {
            throw $this->refuse(403, 'forbidden', 'Only the Testing department reads its reports.');
        }

        $staff->loadMissing('facility');
        $facility = $staff->facility ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        $tz = (string) config('blood_center.timezone', 'Asia/Manila');
        $first = CarbonImmutable::parse($fromDate, $tz)->startOfDay();
        $last = CarbonImmutable::parse($toDate, $tz)->startOfDay();

        if ($last->lt($first)) {
            throw $this->refuse(422, 'bad_period', 'The end of the period comes before its start.');
        }
        if ($first->diffInDays($last) + 1 > self::MAX_DAYS) {
            throw $this->refuse(422, 'period_too_long', 'A report covers a year at most.');
        }

        [$from] = OperationalDay::boundsFor($first->toDateString());
        [, $to] = OperationalDay::boundsFor($last->toDateString());
        $dateOf = fn (mixed $at): string => CarbonImmutable::parse($at)->setTimezone($tz)->toDateString();

        $days = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'panels' => 0, 'reactive' => 0, 'typings' => 0];
        }

        // ── Serology panels ──
        $panels = DonationSerology::query()
            ->whereHas('donation', fn ($q) => $q->where('facility_id', $facility->id))
            ->whereBetween('recorded_at', [$from, $to])
            ->with([
                'donation:id,donor_id,appointment_id,paper_sheet_id,donation_type,donation_date',
                'donation.donorProfile:donor_id,gender,birth_date',
                'donation.appointment:id,event_id',
            ])
            ->get(['id', 'donation_id', 'recorded_at', 'verified_at', ...SerologyMarker::values()]);

        $markerCounts = array_fill_keys(SerologyMarker::values(), 0);
        $months = [];
        $blankRow = fn (string $month): array => ['month' => $month, 'tested' => 0, 'reactive' => 0, 'markers' => array_fill_keys(SerologyMarker::values(), 0)];
        $total = $blankRow('total');
        $profile = [
            'sex' => array_fill_keys(array_keys(self::SEX_LABELS), ['tested' => 0, 'reactive' => 0]),
            'age' => array_fill_keys([...array_keys(self::AGE_BRACKETS), 'not_stated'], ['tested' => 0, 'reactive' => 0]),
            'kind' => array_fill_keys(array_keys(self::KIND_LABELS), ['tested' => 0, 'reactive' => 0]),
        ];
        $awaiting = 0;

        foreach ($panels->sortBy('recorded_at') as $panel) {
            $at = CarbonImmutable::parse($panel->recorded_at)->setTimezone($tz);
            $reactive = $panel->isReactive();
            $awaiting += $panel->isAwaitingVerification() ? 1 : 0;

            $date = $at->toDateString();
            if (isset($days[$date])) {
                $days[$date]['panels']++;
                $days[$date]['reactive'] += $reactive ? 1 : 0;
            }

            $month = $at->format('Y-m');
            $months[$month] ??= $blankRow($month);
            foreach ([&$months[$month], &$total] as &$row) {
                $row['tested']++;
                $row['reactive'] += $reactive ? 1 : 0;
                foreach (SerologyMarker::cases() as $marker) {
                    if ($panel->resultFor($marker)?->isReactive()) {
                        $row['markers'][$marker->value]++;
                    }
                }
            }
            unset($row);

            [$sex, $bracket, $kind] = $this->donorOf($panel->donation, $at);
            foreach ([['sex', $sex], ['age', $bracket], ['kind', $kind]] as [$group, $key]) {
                $profile[$group][$key]['tested']++;
                $profile[$group][$key]['reactive'] += $reactive ? 1 : 0;
            }
        }
        foreach (SerologyMarker::cases() as $marker) {
            $markerCounts[$marker->value] = $total['markers'][$marker->value];
        }

        // ── Typings ──
        $typings = DonationImmunohematology::query()
            ->whereHas('donation', fn ($q) => $q->where('facility_id', $facility->id))
            ->whereBetween('recorded_at', [$from, $to])
            ->with('bloodType:id,code')
            ->get(['id', 'donation_id', 'blood_type_id', 'recorded_at']);

        $byGroup = [];
        foreach ($typings as $typing) {
            $date = $dateOf($typing->recorded_at);
            if (isset($days[$date])) {
                $days[$date]['typings']++;
            }
            $code = $typing->bloodType?->code ?? 'Not typed';
            $byGroup[$code] = ($byGroup[$code] ?? 0) + 1;
        }
        arsort($byGroup);

        $tested = $total['tested'];
        $reactiveTotal = $total['reactive'];

        return [
            'period' => ['from' => $first->toDateString(), 'to' => $last->toDateString(), 'days' => (int) $first->diffInDays($last) + 1],
            'serology' => [
                'tested' => $tested,
                'reactive' => $reactiveTotal,
                'non_reactive' => $tested - $reactiveTotal,
                'awaiting_verification' => $awaiting,
                'reactive_rate' => $this->rate($reactiveTotal, $tested),
                'by_marker' => array_map(fn (SerologyMarker $marker): array => [
                    'marker' => $marker->value,
                    'label' => $marker->label(),
                    'reactive' => $markerCounts[$marker->value],
                    'rate' => $this->rate($markerCounts[$marker->value], $tested),
                ], SerologyMarker::cases()),
            ],
            'months' => array_values($months),
            'total' => $total,
            'profile' => [
                'sex' => $this->groupRows($profile['sex'], self::SEX_LABELS),
                'age' => $this->groupRows($profile['age'], [...array_combine(array_keys(self::AGE_BRACKETS), array_keys(self::AGE_BRACKETS)), 'not_stated' => 'Not stated']),
                'kind' => $this->groupRows($profile['kind'], self::KIND_LABELS),
            ],
            'typing' => [
                'typed' => $typings->count(),
                'by_group' => array_map(fn ($code, $n): array => ['code' => (string) $code, 'count' => $n], array_keys($byGroup), $byGroup),
            ],
            'turnaround' => $this->turnaround($facility->id, $from, $to),
            'referrals' => $this->referrals($facility->id, $from, $to),
            'daily' => array_values($days),
            'previous' => $this->previous($facility->id, $first, $last),
        ];
    }

    /**
     * The donor's sex, age bracket and type of donor at the donation, as the
     * collection record counts them.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function donorOf(?Donation $donation, CarbonImmutable $at): array
    {
        $profile = $donation?->donorProfile;
        $sex = in_array($profile?->gender, ['female', 'male'], true) ? $profile->gender : 'not_stated';

        $age = $profile?->birth_date ? (int) $profile->birth_date->diffInYears($at) : null;
        $bracket = 'not_stated';
        foreach (self::AGE_BRACKETS as $label => [$low, $high]) {
            if ($age !== null && $age >= $low && $age <= $high) {
                $bracket = $label;
            }
        }

        $kind = match (true) {
            $donation === null => 'walk_in',
            $donation->appointment?->event_id !== null || $donation->paper_sheet_id !== null => 'drive',
            default => ($donation->donation_type ?? DonationType::WalkIn)->value,
        };

        return [$sex, $bracket, $kind];
    }

    /**
     * @param  array<string, array{tested: int, reactive: int}>  $counts
     * @param  array<string, string>  $labels
     * @return array<int, array{key: string, label: string, tested: int, reactive: int, rate: float|null}>
     */
    private function groupRows(array $counts, array $labels): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'tested' => $counts[$key]['tested'],
            'reactive' => $counts[$key]['reactive'],
            'rate' => $this->rate($counts[$key]['reactive'], $counts[$key]['tested']),
        ], array_keys($counts));
    }

    /**
     * How long a unit took from the draw to its second clearance, for units
     * fully cleared at the bench in the period: the median, so one unit left
     * over a weekend does not stand for all of them (TestingDashboardService).
     *
     * @return array{median_hours: float|null, units: int}
     */
    private function turnaround(int $facilityId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hours = $this->testingDashboardRepository
            ->recentlyClearedDonations($facilityId, $from)
            ->map(function (Donation $donation): ?array {
                $issued = $donation->clearances->mapWithKeys(fn (DonationClearance $clearance): array => [
                    $clearance->kind->value => CarbonImmutable::parse($clearance->issued_at),
                ]);
                if ($issued->count() < count(ClearanceKind::cases())) {
                    return null;
                }
                $drawn = $donation->collection?->ended_at
                    ? CarbonImmutable::parse($donation->collection->ended_at)
                    : CarbonImmutable::parse($donation->donation_date);
                $cleared = $issued->max();

                return ['drawn' => $drawn, 'cleared' => $cleared];
            })
            ->filter()
            ->filter(fn (array $unit): bool => $unit['cleared']->between($from, $to))
            ->map(fn (array $unit): float => max(0, $unit['drawn']->diffInMinutes($unit['cleared'], false)) / 60)
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
     * Counselling referrals moved in the period: opened, referred to a doctor
     * and closed, each counted when it happened. There is no "contacted"
     * step (project owner, 2026-10-26).
     *
     * @return array{opened: int, referred: int, closed: int}
     */
    private function referrals(int $facilityId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $count = fn (string $column): int => CounsellingReferral::query()
            ->where('facility_id', $facilityId)
            ->whereBetween($column, [$from, $to])
            ->count();

        return [
            'opened' => $count('created_at'),
            'referred' => $count('referred_at'),
            'closed' => $count('closed_at'),
        ];
    }

    /**
     * The period before, as long, for the change on each headline.
     *
     * @return array{tested: int, reactive: int, typed: int}
     */
    private function previous(int $facilityId, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $length = (int) $first->diffInDays($last) + 1;
        [$from] = OperationalDay::boundsFor($first->subDays($length)->toDateString());
        [, $to] = OperationalDay::boundsFor($first->subDay()->toDateString());

        $panels = $this->testingDashboardRepository->panelsRecordedBetween($facilityId, $from, $to);

        return [
            'tested' => $panels->count(),
            'reactive' => $panels->filter(fn (DonationSerology $panel): bool => $panel->isReactive())->count(),
            'typed' => $this->testingDashboardRepository->typingsRecordedBetween($facilityId, $from, $to),
        ];
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole ? round($part / $whole * 100, 1) : null;
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['code' => $code, 'message' => $message], $status));
    }
}
