<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Repository\CollectionDashboardRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The centre's forecast for the weeks ahead, each department's own part:
 *
 *   demand      hospital requests by blood type and component, against the
 *               stock on the shelf, with the emergencies apart (Issuance)
 *   collection  the donors each blood type needs, against what is being
 *               collected and what is booked (Donor & Collection)
 *   processing  the donations coming in to separate, and the components the
 *               demand calls for (Processing)
 *   testing     the samples coming in, day by day (Testing)
 *   billing     the requests and the amount coming in to bill (Billing)
 *
 * The Supervisor sees every part; the Admin, who runs staff and settings,
 * none. Each is a weighted average of the last HISTORY_WEEKS full weeks, the
 * recent weeks counting more, with the last four weeks' lowest and highest as
 * its range. Statistics on the centre's own records, not a predictive model.
 */
class DemandForecastService
{
    public const HISTORY_WEEKS = 12;

    public const HORIZON_WEEKS = 4;

    /** Weeks of stock below which a blood type and component is short, then to watch. */
    private const SHORT_WEEKS = 1;

    private const WATCH_WEEKS = 2;

    public function __construct(
        private readonly CollectionDashboardRepository $collectionDashboardRepository
    ) {}

    /**
     * The parts of the forecast this person plans with.
     *
     * @return array<int, string>
     */
    public function sectionsFor(User $staff): array
    {
        if ($staff->isCenterSupervisor()) {
            return ['demand', 'collection', 'processing', 'testing', 'billing'];
        }
        if ($staff->isCenterAdmin()) {
            return [];
        }

        $abilities = $staff->abilities();
        $has = fn (string $ability): bool => in_array($ability, $abilities, true);

        return array_values(array_filter([
            $has('inventory.view') ? 'demand' : null,
            $has('donations.view') ? 'collection' : null,
            $has('lab.record_components') ? 'processing' : null,
            $has('lab.record_serology') ? 'testing' : null,
            $has('billing.record_payment') ? 'billing' : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function forecast(User $staff): array
    {
        $staff->loadMissing('facility');
        $facility = $staff->facility ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
        $sections = $this->sectionsFor($staff);

        if ($sections === []) {
            throw $this->refuse(403, 'forecast_not_available', 'The forecast is for the departments that plan stock, collection, laboratory work and billing.');
        }

        $tz = (string) config('blood_center.timezone', 'Asia/Manila');
        $thisWeek = CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY);
        $first = $thisWeek->subWeeks(self::HISTORY_WEEKS);
        $weeks = [];
        for ($w = 0; $w < self::HISTORY_WEEKS; $w++) {
            $weeks[] = $first->addWeeks($w)->toDateString();
        }
        $horizon = [];
        for ($w = 0; $w < self::HORIZON_WEEKS; $w++) {
            $horizon[] = $thisWeek->addWeeks($w)->toDateString();
        }

        $context = [
            'facility' => $facility,
            'tz' => $tz,
            'first' => $first,
            'thisWeek' => $thisWeek,
            'weekOf' => fn (mixed $at): ?int => $this->weekIndex($at, $first, $tz),
        ];

        $needsDemand = array_intersect($sections, ['demand', 'collection', 'processing', 'billing']) !== [];
        $needsDonations = array_intersect($sections, ['collection', 'processing', 'testing']) !== [];
        $demand = $needsDemand ? $this->demandRows($context) : collect();
        $donations = $needsDonations ? $this->collectedDonations($context) : collect();

        $result = [
            'as_of' => CarbonImmutable::now($tz)->toIso8601String(),
            'method' => [
                'history_weeks' => self::HISTORY_WEEKS,
                'horizon_weeks' => self::HORIZON_WEEKS,
                'from' => $first->toDateString(),
                'to' => $thisWeek->subDay()->toDateString(),
            ],
            'weeks' => $weeks,
            'horizon' => $horizon,
            'sections' => $sections,
        ];

        foreach ($sections as $section) {
            $result[$section] = match ($section) {
                'demand' => $this->demandSection($context, $demand),
                'collection' => $this->collectionSection($context, $demand, $donations),
                'processing' => $this->processingSection($context, $demand, $donations),
                'testing' => $this->testingSection($context, $donations),
                'billing' => $this->billingSection($context, $demand),
            };
        }

        return $result;
    }

    /**
     * Every unit requested of this centre in the history, one row per request
     * item: its week, blood type, component, quantity and urgency.
     *
     * @param  array<string, mixed>  $c
     * @return Collection<int, object>
     */
    private function demandRows(array $c): Collection
    {
        return DB::table('blood_request_items as i')
            ->join('blood_requests as r', 'r.id', '=', 'i.request_id')
            ->where('r.target_facility_id', $c['facility']->id)
            ->where('r.status', '!=', BloodRequestStatus::Cancelled->value)
            ->where('r.request_date', '>=', $c['first']->utc())
            ->where('r.request_date', '<', $c['thisWeek']->utc())
            ->get([
                'r.id as request_id',
                'r.request_date',
                'r.urgency_level',
                DB::raw('coalesce(i.blood_type_id, r.blood_type_id) as blood_type_id'),
                'i.component_id',
                'i.quantity',
            ])
            ->map(function (object $row) use ($c): object {
                $row->week = $c['weekOf']($row->request_date);

                return $row;
            })
            ->filter(fn (object $row): bool => $row->week !== null)
            ->values();
    }

    /**
     * Every visit in the history where blood was collected, with its week,
     * weekday and the donor's blood type.
     *
     * @param  array<string, mixed>  $c
     * @return Collection<int, object>
     */
    private function collectedDonations(array $c): Collection
    {
        return Donation::query()
            ->where('facility_id', $c['facility']->id)
            ->where('donation_date', '>=', $c['first']->utc())
            ->where('donation_date', '<', $c['thisWeek']->utc())
            ->with(['screening:id,donation_id,outcome', 'donorProfile:donor_id,blood_type_id'])
            ->withExists('collection')
            ->get(['id', 'donor_id', 'donation_date', 'status'])
            ->filter(fn (Donation $visit): bool => $this->collectionDashboardRepository->stageOf($visit) === 'collected')
            ->map(fn (Donation $visit): object => (object) [
                'week' => $c['weekOf']($visit->donation_date),
                'weekday' => CarbonImmutable::parse($visit->donation_date)->setTimezone($c['tz'])->dayOfWeekIso - 1,
                'blood_type_id' => $visit->donorProfile?->blood_type_id,
            ])
            ->filter(fn (object $row): bool => $row->week !== null)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  Collection<int, object>  $demand
     * @return array<string, mixed>
     */
    private function demandSection(array $c, Collection $demand): array
    {
        $types = BloodType::query()->pluck('code', 'id');
        $components = BloodComponent::query()->pluck('name', 'id');
        $stock = BloodUnit::query()
            ->where('facility_id', $c['facility']->id)
            ->where('status', BloodUnitStatus::Available->value)
            ->whereDate('expiry_date', '>=', CarbonImmutable::now($c['tz'])->toDateString())
            ->groupBy('blood_type_id', 'component_id')
            ->selectRaw('blood_type_id, component_id, count(*) as units')
            ->get()
            ->mapWithKeys(fn ($row): array => ["{$row->blood_type_id}:{$row->component_id}" => (int) $row->units]);

        $today = CarbonImmutable::now($c['tz'])->startOfDay();
        $rows = $demand->groupBy(fn (object $r): string => "{$r->blood_type_id}:{$r->component_id}")
            ->map(function (Collection $group, string $key) use ($types, $components, $stock, $today): array {
                [$typeId, $componentId] = array_map('intval', explode(':', $key));
                $series = $this->series($group, fn (object $r): int => (int) $r->quantity);
                $estimate = $this->estimate($series);
                $units = $stock[$key] ?? 0;
                $cover = $estimate['per_week'] > 0 ? $units / $estimate['per_week'] : null;

                return [
                    'blood_type' => $types[$typeId] ?? '—',
                    'component' => $components[$componentId] ?? '—',
                    ...$estimate,
                    'stock' => $units,
                    'weeks_of_cover' => $cover === null ? null : round($cover, 1),
                    'runs_out_on' => $cover === null ? null : $today->addDays((int) floor($cover * 7))->toDateString(),
                    'status' => match (true) {
                        $cover === null => 'ok',
                        $cover < self::SHORT_WEEKS => 'short',
                        $cover < self::WATCH_WEEKS => 'watch',
                        default => 'ok',
                    },
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => array_search($a['status'], ['short', 'watch', 'ok'], true) <=> array_search($b['status'], ['short', 'watch', 'ok'], true),
                fn (array $a, array $b): int => $b['per_week'] <=> $a['per_week'],
            ])
            ->values()
            ->all();

        $totalSeries = $this->series($demand, fn (object $r): int => (int) $r->quantity);

        // Emergencies: counted by request, not by unit.
        $emergencies = $demand->where('urgency_level', 'emergency')->unique('request_id');
        $allRequests = $demand->unique('request_id');
        $emergencySeries = $this->series($emergencies, fn (): int => 1);
        $byType = $emergencies->countBy(fn (object $r): string => $types[$r->blood_type_id] ?? '—')->sortDesc();
        $byWeekday = $emergencies->countBy(fn (object $r): int => CarbonImmutable::parse($r->request_date)->setTimezone($c['tz'])->dayOfWeekIso - 1);

        return [
            'total' => [...$this->estimate($totalSeries), 'series' => $totalSeries],
            'rows' => $rows,
            'short' => count(array_filter($rows, fn (array $r): bool => $r['status'] === 'short')),
            'watch' => count(array_filter($rows, fn (array $r): bool => $r['status'] === 'watch')),
            'emergency' => [
                ...$this->estimate($emergencySeries),
                'series' => $emergencySeries,
                'requests' => $emergencies->count(),
                'share' => $allRequests->count() ? round($emergencies->count() / $allRequests->count() * 100, 1) : null,
                'by_blood_type' => $byType->take(4)->map(fn (int $n, string $code): array => ['code' => $code, 'count' => $n])->values()->all(),
                'busiest_weekday' => $byWeekday->isEmpty() ? null : (int) $byWeekday->sortDesc()->keys()->first(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  Collection<int, object>  $demand
     * @param  Collection<int, object>  $donations
     * @return array<string, mixed>
     */
    private function collectionSection(array $c, Collection $demand, Collection $donations): array
    {
        $types = BloodType::query()->orderBy('id')->pluck('code', 'id');

        // A donation gives one unit of each component, so a blood type needs
        // as many donors a week as its most-requested component takes.
        $needed = $demand->groupBy('blood_type_id')->map(
            fn (Collection $rows): float => $rows->groupBy('component_id')
                ->map(fn (Collection $g): float => $this->estimate($this->series($g, fn (object $r): int => (int) $r->quantity))['per_week'])
                ->max() ?? 0.0
        );

        $booked = DonationAppointment::query()
            ->where('facility_id', $c['facility']->id)
            ->whereIn('status', AppointmentStatus::activeValues())
            ->where('appointment_datetime', '>=', CarbonImmutable::now($c['tz'])->utc())
            ->where('appointment_datetime', '<', $c['thisWeek']->addWeeks(self::HORIZON_WEEKS)->utc())
            ->with('donorProfile:donor_id,blood_type_id')
            ->get(['id', 'donor_id', 'appointment_datetime']);
        $bookedByType = $booked->countBy(fn (DonationAppointment $a): string => (string) ($a->donorProfile?->blood_type_id ?? 'untyped'));

        $rows = $types->map(function (string $code, int $id) use ($needed, $donations, $bookedByType): array {
            $collecting = $this->estimate($this->series($donations->where('blood_type_id', $id), fn (): int => 1));
            $need = round($needed[$id] ?? 0, 1);
            $gap = round($need - $collecting['per_week'], 1);

            return [
                'blood_type' => $code,
                'needed_per_week' => $need,
                'collecting_per_week' => $collecting['per_week'],
                'gap_per_week' => $gap,
                'booked' => $bookedByType[(string) $id] ?? 0,
                'status' => $gap > 0.5 ? 'short' : 'ok',
            ];
        })
            ->filter(fn (array $r): bool => $r['needed_per_week'] > 0 || $r['collecting_per_week'] > 0 || $r['booked'] > 0)
            ->sortByDesc('gap_per_week')
            ->values()
            ->all();

        $overall = $this->series($donations, fn (): int => 1);

        return [
            'donations' => [...$this->estimate($overall), 'series' => $overall],
            'needed_per_week' => round(array_sum(array_column($rows, 'needed_per_week')), 1),
            'booked' => $booked->count(),
            'booked_untyped' => $bookedByType['untyped'] ?? 0,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  Collection<int, object>  $demand
     * @param  Collection<int, object>  $donations
     * @return array<string, mixed>
     */
    private function processingSection(array $c, Collection $demand, Collection $donations): array
    {
        $components = BloodComponent::query()->pluck('name', 'id');
        $series = $this->series($donations, fn (): int => 1);

        $mix = $demand->groupBy('component_id')
            ->map(fn (Collection $rows, int|string $id): array => [
                'component' => $components[$id] ?? '—',
                ...$this->estimate($this->series($rows, fn (object $r): int => (int) $r->quantity)),
            ])
            ->sortByDesc('per_week')
            ->values()
            ->all();

        return [
            'donations' => [...$this->estimate($series), 'series' => $series],
            'booked_next_week' => $this->bookedBetween($c, CarbonImmutable::now($c['tz']), CarbonImmutable::now($c['tz'])->addDays(7)),
            'components' => $mix,
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  Collection<int, object>  $donations
     * @return array<string, mixed>
     */
    private function testingSection(array $c, Collection $donations): array
    {
        $series = $this->series($donations, fn (): int => 1);
        $weekdays = $donations->countBy('weekday');

        // The next seven days: each weekday's average over the history, and what is booked.
        $today = CarbonImmutable::now($c['tz'])->startOfDay();
        $days = [];
        for ($d = 0; $d < 7; $d++) {
            $day = $today->addDays($d);
            $days[] = [
                'date' => $day->toDateString(),
                'expected' => round(($weekdays[$day->dayOfWeekIso - 1] ?? 0) / self::HISTORY_WEEKS, 1),
                'booked' => $this->bookedBetween($c, $day, $day->endOfDay()),
            ];
        }

        return [
            'samples' => [...$this->estimate($series), 'series' => $series],
            'days' => $days,
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  Collection<int, object>  $demand
     * @return array<string, mixed>
     */
    private function billingSection(array $c, Collection $demand): array
    {
        $components = BloodComponent::query()->get(['id', 'name', 'price'])->keyBy('id');
        $prices = FacilityBloodComponent::query()
            ->where('facility_id', $c['facility']->id)
            ->pluck('price', 'component_id');

        $requests = $this->series($demand->unique('request_id'), fn (): int => 1);
        $byComponent = $demand->groupBy('component_id')->map(function (Collection $rows, int|string $id) use ($components, $prices): array {
            $estimate = $this->estimate($this->series($rows, fn (object $r): int => (int) $r->quantity));
            $price = (float) ($prices[$id] ?? $components[$id]?->price ?? 0);

            return [
                'component' => $components[$id]?->name ?? '—',
                'units_per_week' => $estimate['per_week'],
                'price' => $price,
                'amount_per_week' => round($estimate['per_week'] * $price, 2),
            ];
        })->sortByDesc('amount_per_week')->values();

        $amount = round((float) $byComponent->sum('amount_per_week'), 2);

        return [
            'requests' => [...$this->estimate($requests), 'series' => $requests],
            'units_per_week' => round((float) $byComponent->sum('units_per_week'), 1),
            'amount_per_week' => $amount,
            'amount_horizon' => round($amount * self::HORIZON_WEEKS, 2),
            'components' => $byComponent->all(),
        ];
    }

    /**
     * Each history week's sum.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, int>
     */
    private function series(Collection $rows, callable $value): array
    {
        $series = array_fill(0, self::HISTORY_WEEKS, 0);
        foreach ($rows as $row) {
            $series[$row->week] += $value($row);
        }

        return $series;
    }

    /**
     * The week ahead from the history: a weighted average, the latest week
     * weighing HISTORY_WEEKS times the earliest; the last four weeks' lowest
     * and highest as its range; and the change of the last four weeks against
     * the four before.
     *
     * @param  array<int, int>  $series
     * @return array{per_week: float, low: int, high: int, trend: ?float}
     */
    private function estimate(array $series): array
    {
        $weighted = 0;
        $weights = 0;
        foreach (array_values($series) as $i => $value) {
            $weighted += ($i + 1) * $value;
            $weights += $i + 1;
        }

        $recent = array_slice($series, -4);
        $before = array_slice($series, -8, 4);
        $recentSum = array_sum($recent);
        $beforeSum = array_sum($before);

        return [
            'per_week' => $weights ? round($weighted / $weights, 1) : 0.0,
            'low' => min($recent),
            'high' => max($recent),
            'trend' => $beforeSum > 0 ? round(($recentSum - $beforeSum) / $beforeSum * 100, 1) : null,
        ];
    }

    /** The history week an instant falls in, or null outside it. */
    private function weekIndex(mixed $at, CarbonImmutable $first, string $tz): ?int
    {
        $day = CarbonImmutable::parse($at)->setTimezone($tz)->startOfDay();
        $index = (int) floor($first->diffInDays($day, false) / 7);

        return $index >= 0 && $index < self::HISTORY_WEEKS ? $index : null;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function bookedBetween(array $c, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DonationAppointment::query()
            ->where('facility_id', $c['facility']->id)
            ->whereIn('status', AppointmentStatus::activeValues())
            ->where('appointment_datetime', '>=', $from->utc())
            ->where('appointment_datetime', '<=', $to->utc())
            ->count();
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
