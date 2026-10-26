<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\DonationType;
use App\Enums\DonorType;
use App\Enums\ScreeningOutcome;
use App\Models\DeferralCode;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Repository\CollectionDashboardRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * The Donor & Collection department's reports, for a period: donations and
 * deferrals at the counter, the appointments booked, and the drives held.
 *
 * Counted the way the Collection dashboard counts (CollectionDashboardRepository::stageOf):
 * a donation is blood collected, a deferral a visit the physician deferred.
 * Names no donor: the reasons are the physician's own words, grouped.
 *
 * The donor profile is the centre's monthly collection record (SNBC record):
 * every donation by sex, age bracket, type of donor (walk-in, TS or NTS, as
 * the counter picked it, or at a mobile drive) and donor status (first time,
 * new to the centre, repeat, lapsed).
 */
class CollectionReportService
{
    public const MAX_DAYS = 366;

    /** The most common deferral reasons listed. */
    private const REASONS = 6;

    /** A donor whose last donation is older than this is lapsed (DonorQuestionnaireService). */
    public const LAPSED_AFTER_DAYS = 365;

    /** The record's age brackets, as the paper sheet heads them. */
    private const AGE_BRACKETS = ['16-17' => [16, 17], '18-24' => [18, 24], '25-44' => [25, 44], '45-64' => [45, 64], '65+' => [65, 200]];

    public function __construct(
        private readonly CollectionDashboardRepository $collectionDashboardRepository
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(User $staff, string $fromDate, string $toDate): array
    {
        $staff->loadMissing('facility');
        $facility = $staff->facility ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        // Every counter role reads this report; only the clinical record's
        // holder reads what a deferral code stands for.
        $clinical = $staff->can('donors.view_clinical');

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
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'donations' => 0, 'deferrals' => 0, 'appointments' => 0];
        }

        // ── Donations and deferrals ──
        $visits = Donation::query()
            ->where('facility_id', $facility->id)
            ->whereBetween('donation_date', [$from, $to])
            ->with([
                'screening:id,donation_id,outcome,deferral_code,deferral_reason',
                'donorProfile:donor_id,blood_type_id,gender,birth_date,last_donation_date',
                'donorProfile.bloodType:id,code',
                'appointment:id,event_id',
            ])
            ->withExists('collection')
            ->get(['id', 'donor_id', 'appointment_id', 'paper_sheet_id', 'donation_type', 'donation_date', 'status']);

        $donations = 0;
        $given = [];
        $byGroup = [];
        $byOutcome = [];
        $reasons = [];
        foreach ($visits as $visit) {
            $stage = $this->collectionDashboardRepository->stageOf($visit);
            $date = $dateOf($visit->donation_date);

            if ($stage === 'collected') {
                $donations++;
                $given[] = $visit;
                $days[$date]['donations'] = ($days[$date]['donations'] ?? 0) + 1;
                $code = $visit->donorProfile?->bloodType?->code ?? 'Untyped';
                $byGroup[$code] = ($byGroup[$code] ?? 0) + 1;
            } elseif ($stage === 'deferred') {
                $days[$date]['deferrals'] = ($days[$date]['deferrals'] ?? 0) + 1;
                $outcome = $visit->screening?->outcome;
                $label = $outcome instanceof ScreeningOutcome ? $outcome->label() : 'Deferred';
                $byOutcome[$label] = ($byOutcome[$label] ?? 0) + 1;
                $this->tallyReason($reasons, $visit->screening?->deferral_code, $visit->screening?->deferral_reason, $clinical);
            }
        }
        $deferrals = array_sum($byOutcome);
        uasort($reasons, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        arsort($byGroup);

        // ── Appointments ──
        $appointments = DonationAppointment::query()
            ->where('facility_id', $facility->id)
            ->whereBetween('appointment_datetime', [$from, $to])
            ->get(['id', 'appointment_datetime', 'status', 'event_id']);

        foreach ($appointments as $appointment) {
            $date = $dateOf($appointment->appointment_datetime);
            if (isset($days[$date])) {
                $days[$date]['appointments']++;
            }
        }
        $count = fn (AppointmentStatus $status): int => $appointments->filter(fn (DonationAppointment $a): bool => $a->status === $status)->count();
        $completed = $count(AppointmentStatus::Completed);
        $noShow = $count(AppointmentStatus::NoShow);

        // ── Drives ──
        $drives = MobileEvent::query()
            ->where('facility_id', $facility->id)
            ->whereBetween('event_date', [$first->toDateString(), $last->toDateString()])
            ->get(['id', 'name', 'event_date', 'max_capacity']);

        return [
            'period' => ['from' => $first->toDateString(), 'to' => $last->toDateString(), 'days' => (int) $first->diffInDays($last) + 1],
            'donations' => [
                'collected' => $donations,
                'deferred' => $deferrals,
                'deferral_rate' => ($donations + $deferrals) ? round($deferrals / ($donations + $deferrals) * 100, 1) : null,
                'by_blood_group' => array_map(fn ($code, $n): array => ['code' => (string) $code, 'count' => $n], array_keys($byGroup), $byGroup),
            ],
            'deferrals' => [
                'by_type' => array_map(fn ($label, $n): array => ['type' => (string) $label, 'count' => $n], array_keys($byOutcome), $byOutcome),
                'top_reasons' => array_values(array_slice($reasons, 0, self::REASONS, true)),
            ],
            'appointments' => [
                'booked' => $appointments->count(),
                'completed' => $completed,
                'no_show' => $noShow,
                'cancelled' => $count(AppointmentStatus::Cancelled),
                'show_rate' => ($completed + $noShow) ? round($completed / ($completed + $noShow) * 100, 1) : null,
                'from_drives' => $appointments->whereNotNull('event_id')->count(),
            ],
            'drives' => [
                'held' => $drives->count(),
                'list' => $drives->map(fn (MobileEvent $d): array => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'date' => $d->event_date->toDateString(),
                    'booked' => $appointments->where('event_id', $d->id)->count(),
                    'capacity' => $d->max_capacity,
                ])->values()->all(),
            ],
            'daily' => array_values($days),
            'donor_profile' => $this->donorProfile($facility->id, collect($given), $tz, $last),
            'busiest' => $this->busiest($visits, $tz),
            'opening_hours' => $this->openingHours($facility),
            'previous' => $this->previous($facility->id, $first, $last),
        ];
    }

    /**
     * Count one deferral under its code (project owner, 2026-10-24).
     *
     * A coded deferral is counted by its code, with the reason it stood for
     * when only the clinical record's holder is reading. One with no code (an
     * "Other", or one recorded before codes existed) has only its typed
     * reason: that holder sees it as typed, everyone else sees all of them as
     * one Other line.
     *
     * @param  array<string, array{code: ?string, reason: ?string, label: string, count: int}>  $reasons
     */
    private function tallyReason(array &$reasons, ?string $code, ?string $typed, bool $clinical): void
    {
        $typed = trim((string) $typed) ?: null;
        $other = DeferralCode::OTHER;

        [$key, $reason, $label] = match (true) {
            $code !== null => ["code:{$code}", $clinical ? $typed : null, $clinical && $typed !== null ? "{$code} · {$typed}" : $code],
            $clinical => ['text:'.($typed ?? ''), $typed, $typed !== null ? "{$other} · {$typed}" : 'No reason recorded'],
            default => ['other', null, $other],
        };

        $reasons[$key] ??= ['code' => $code, 'reason' => $reason, 'label' => $label, 'count' => 0];
        $reasons[$key]['count']++;
    }

    /**
     * When the centre is open, for the busiest-hours grid: the days from its
     * operating hours ("Mon - Fri 8 AM - 3 PM"), the hours from its first to
     * its last appointment slot. Every day and hour when nothing says
     * otherwise, and "24/7" means just that.
     *
     * @return array{days: array<int, int>, from: int, to: int, label: ?string}
     */
    private function openingHours(Facility $facility): array
    {
        $text = strtolower((string) $facility->operating_hours);
        $allDays = range(0, 6);

        if (str_contains($text, '24/7') || str_contains($text, '24 hours')) {
            return ['days' => $allDays, 'from' => 0, 'to' => 24, 'label' => $facility->operating_hours];
        }

        // Days as written: a range ("Mon - Fri", "Mon to Sat") or a list ("Mon, Wed, Fri").
        $names = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $pattern = '(mon|tue|wed|thu|fri|sat|sun)[a-z]*';
        $days = [];
        if (preg_match_all("/{$pattern}\\s*(?:-|–|to)\\s*{$pattern}/u", $text, $ranges, PREG_SET_ORDER)) {
            foreach ($ranges as $range) {
                $start = array_search($range[1], $names, true);
                $end = array_search($range[2], $names, true);
                for ($d = $start; ; $d = ($d + 1) % 7) {
                    $days[] = $d;
                    if ($d === $end) {
                        break;
                    }
                }
            }
        }
        if (preg_match_all("/{$pattern}/u", $text, $single)) {
            foreach ($single[1] as $name) {
                $days[] = array_search($name, $names, true);
            }
        }
        $days = $days === [] || str_contains($text, 'daily') ? $allDays : array_values(array_unique($days));
        // The days set in the booking schedule win over the written hours.
        if (is_array($facility->open_days) && $facility->open_days !== []) {
            $days = array_map('intval', $facility->open_days);
        }
        sort($days);

        // Hours: the first slot's hour to the hour the last one ends in.
        $from = $facility->slots_start_at ? (int) substr((string) $facility->slots_start_at, 0, 2) : 0;
        // The last slot starts at slots_end_at, so its hour is part of the day.
        $to = 24;
        if ($facility->slots_end_at) {
            $to = min(24, (int) substr((string) $facility->slots_end_at, 0, 2) + 1);
        }
        if ($to <= $from) {
            [$from, $to] = [0, 24];
        }

        return ['days' => $days, 'from' => $from, 'to' => $to, 'label' => $facility->operating_hours];
    }

    /**
     * When donors reach the counter: every visit in the period by weekday
     * (0 = Monday) and hour of arrival, as a 7 x 24 grid.
     *
     * @param  Collection<int, Donation>  $visits
     * @return array<int, array<int, int>>
     */
    private function busiest(Collection $visits, string $tz): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($visits as $visit) {
            $at = CarbonImmutable::parse($visit->donation_date)->setTimezone($tz);
            $grid[$at->dayOfWeekIso - 1][$at->hour]++;
        }

        return $grid;
    }

    /**
     * The period of the same length just before this one, for "vs previous".
     *
     * @return array<string, mixed>
     */
    private function previous(int $facilityId, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $days = (int) $first->diffInDays($last) + 1;
        $prevFirst = $first->subDays($days);
        $prevLast = $first->subDay();
        [$from] = OperationalDay::boundsFor($prevFirst->toDateString());
        [, $to] = OperationalDay::boundsFor($prevLast->toDateString());

        $stages = Donation::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('donation_date', [$from, $to])
            ->with('screening:id,donation_id,outcome')
            ->withExists('collection')
            ->get(['id', 'donation_date', 'status'])
            ->map(fn (Donation $visit): string => $this->collectionDashboardRepository->stageOf($visit))
            ->countBy();
        $collected = $stages['collected'] ?? 0;
        $deferred = $stages['deferred'] ?? 0;

        $appointments = DonationAppointment::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('appointment_datetime', [$from, $to])
            ->pluck('status');
        $completed = $appointments->filter(fn ($s): bool => $s === AppointmentStatus::Completed)->count();
        $noShow = $appointments->filter(fn ($s): bool => $s === AppointmentStatus::NoShow)->count();

        return [
            'from' => $prevFirst->toDateString(),
            'to' => $prevLast->toDateString(),
            'collected' => $collected,
            'deferred' => $deferred,
            'deferral_rate' => ($collected + $deferred) ? round($deferred / ($collected + $deferred) * 100, 1) : null,
            'booked' => $appointments->count(),
            'show_rate' => ($completed + $noShow) ? round($completed / ($completed + $noShow) * 100, 1) : null,
        ];
    }

    /**
     * The monthly collection record: each donation in the period by sex, age
     * bracket and type of donor, month by month, with the period's total.
     *
     * The type of donor is the one the questionnaire derives, as it stood at
     * that donation: first time (never donated), new to the centre (donated
     * elsewhere, first time here), repeat (donated here within a year) or
     * lapsed (donated here, but over a year before).
     *
     * @param  Collection<int, Donation>  $given
     * @return array<string, mixed>
     */
    private function donorProfile(int $facilityId, Collection $given, string $tz, CarbonImmutable $periodEnd): array
    {
        // Every earlier donation by these donors, anywhere in RedAgos.
        $earlier = Donation::query()
            ->whereIn('donor_id', $given->pluck('donor_id')->unique()->values())
            ->where('donation_date', '<', $given->max('donation_date') ?? $periodEnd)
            ->where(fn ($q) => $q->where('status', DonationStatus::Completed->value)->orWhereHas('collection'))
            ->get(['id', 'donor_id', 'facility_id', 'donation_date'])
            ->groupBy('donor_id');

        $blank = fn (): array => [
            'total' => 0,
            'sex' => ['female' => 0, 'male' => 0, 'not_stated' => 0],
            'age' => array_fill_keys([...array_keys(self::AGE_BRACKETS), 'not_stated'], 0),
            'type' => array_fill_keys(DonorType::values(), 0),
            'kind' => ['walk_in' => 0, 'ts' => 0, 'nts' => 0, 'drive' => 0],
        ];

        $months = [];
        $total = $blank();
        foreach ($given->sortBy('donation_date') as $visit) {
            $at = CarbonImmutable::parse($visit->donation_date)->setTimezone($tz);
            $profile = $visit->donorProfile;

            $sex = in_array($profile?->gender, ['female', 'male'], true) ? $profile->gender : 'not_stated';
            $age = $profile?->birth_date ? (int) $profile->birth_date->diffInYears($at) : null;
            $bracket = 'not_stated';
            foreach (self::AGE_BRACKETS as $label => [$low, $high]) {
                if ($age !== null && $age >= $low && $age <= $high) {
                    $bracket = $label;
                }
            }

            $before = ($earlier[$visit->donor_id] ?? collect())->filter(fn (Donation $d): bool => $d->donation_date < $visit->donation_date && $d->id !== $visit->id);
            $here = $before->where('facility_id', $facilityId);
            $type = match (true) {
                $here->isNotEmpty() => CarbonImmutable::parse($here->max('donation_date'))->diffInDays($at) > self::LAPSED_AFTER_DAYS
                    ? DonorType::Lapsed
                    : DonorType::RepeatRetained,
                // Donated elsewhere: another centre on RedAgos, or before it, as the donor declared.
                $before->isNotEmpty() || ($profile?->last_donation_date && $profile->last_donation_date->lt($at)) => DonorType::NewToCentre,
                default => DonorType::FirstTime,
            };

            // Type of donor, as the counter picked it; a drive's donors are counted apart.
            $kind = match (true) {
                $visit->appointment?->event_id !== null || $visit->paper_sheet_id !== null => 'drive',
                default => ($visit->donation_type ?? DonationType::WalkIn)->value,
            };

            $month = $at->format('Y-m');
            $months[$month] ??= ['month' => $month] + $blank();
            foreach ([&$months[$month], &$total] as &$row) {
                $row['total']++;
                $row['sex'][$sex]++;
                $row['age'][$bracket]++;
                $row['type'][$type->value]++;
                $row['kind'][$kind]++;
            }
            unset($row);
        }

        return [
            'months' => array_values($months),
            'total' => $total,
            'type_labels' => collect(DonorType::cases())->mapWithKeys(fn (DonorType $t): array => [$t->value => $t->label()])->all(),
            'lapsed_after_days' => self::LAPSED_AFTER_DAYS,
            'lapsed_donors' => $this->lapsedDonors($facilityId, $periodEnd),
        ];
    }

    /**
     * The donors who have given here, whose last donation anywhere is over a
     * year before the end of the period: the ones to win back.
     */
    private function lapsedDonors(int $facilityId, CarbonImmutable $periodEnd): int
    {
        $donors = Donation::query()
            ->where('facility_id', $facilityId)
            ->where(fn ($q) => $q->where('status', DonationStatus::Completed->value)->orWhereHas('collection'))
            ->distinct()
            ->pluck('donor_id');

        return Donation::query()
            ->whereIn('donor_id', $donors)
            ->where('donation_date', '<=', $periodEnd->endOfDay())
            ->where(fn ($q) => $q->where('status', DonationStatus::Completed->value)->orWhereHas('collection'))
            ->groupBy('donor_id')
            ->selectRaw('donor_id, max(donation_date) as last_at')
            ->get()
            ->filter(fn ($row): bool => CarbonImmutable::parse($row->last_at)->diffInDays($periodEnd) > self::LAPSED_AFTER_DAYS)
            ->count();
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
