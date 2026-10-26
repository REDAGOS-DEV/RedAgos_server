<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use App\Models\AuditLog;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Repository\CollectionDashboardRepository;
use App\Support\AuditActions;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The Blood Center Admin's reports, for a period: the centre's work in
 * figures, the staff accounts and access, and who did what (reports.view_all).
 *
 * Every figure comes from the records themselves, counted the way the
 * department dashboards count them, so a number here matches the one a
 * department sees: a donation is blood collected and a deferral a visit the
 * physician deferred (CollectionDashboardRepository::stageOf).
 */
class AdminReportService
{
    /** The longest period one report covers. */
    public const MAX_DAYS = 366;

    /** Staff and access events listed in full, newest first. */
    private const EVENTS = 50;

    /** The staff actions the access report counts, by what they mean. */
    private const STAFF_GROUPS = [
        'created' => ['staff.created'],
        'role_changes' => ['staff.role_changed', 'staff.privileges_changed', 'staff.management_level_changed'],
        'status_changes' => ['staff.status_changed'],
        'removed' => ['staff.deleted'],
        'restored' => ['staff.restored'],
    ];

    public function __construct(
        private readonly CollectionDashboardRepository $collectionDashboardRepository
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(User $admin, string $fromDate, string $toDate): array
    {
        $admin->loadMissing('facility');
        $facility = $admin->facility ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        $first = CarbonImmutable::parse($fromDate, $this->timezone())->startOfDay();
        $last = CarbonImmutable::parse($toDate, $this->timezone())->startOfDay();

        if ($last->lt($first)) {
            throw $this->refuse(422, 'bad_period', 'The end of the period comes before its start.');
        }
        if ($first->diffInDays($last) + 1 > self::MAX_DAYS) {
            throw $this->refuse(422, 'period_too_long', 'A report covers a year at most.');
        }

        [$from] = OperationalDay::boundsFor($first->toDateString());
        [, $to] = OperationalDay::boundsFor($last->toDateString());

        return [
            'period' => ['from' => $first->toDateString(), 'to' => $last->toDateString(), 'days' => (int) $first->diffInDays($last) + 1],
            'centre' => $this->centre($facility, $first, $last, $from, $to),
            'staff' => $this->staff($facility, $from, $to),
            'activity' => $this->activity($facility, $from, $to),
        ];
    }

    /**
     * Donations, requests and units over the period, with a daily series.
     *
     * @return array<string, mixed>
     */
    private function centre(Facility $facility, CarbonImmutable $first, CarbonImmutable $last, mixed $from, mixed $to): array
    {
        $days = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'donations' => 0, 'deferrals' => 0, 'requests' => 0];
        }

        $donations = 0;
        $deferrals = 0;
        foreach ($this->collectionDashboardRepository->visitsBetween($facility->id, $from, $to) as $visit) {
            $stage = $this->collectionDashboardRepository->stageOf($visit);
            $date = $this->dateOf($visit->donation_date);

            if ($stage === 'collected') {
                $donations++;
                if (isset($days[$date])) {
                    $days[$date]['donations']++;
                }
            } elseif ($stage === 'deferred') {
                $deferrals++;
                if (isset($days[$date])) {
                    $days[$date]['deferrals']++;
                }
            }
        }

        $requests = BloodRequest::query()
            ->where('target_facility_id', $facility->id)
            ->whereBetween('request_date', [$from, $to])
            ->get(['id', 'status', 'urgency_level', 'request_date', 'fulfilled_at']);

        foreach ($requests as $request) {
            $date = $this->dateOf($request->request_date);
            if (isset($days[$date])) {
                $days[$date]['requests']++;
            }
        }

        $fulfilled = $requests->filter(fn (BloodRequest $r): bool => $r->status === BloodRequestStatus::Fulfilled);
        $hours = $fulfilled
            ->filter(fn (BloodRequest $r): bool => $r->fulfilled_at !== null && $r->request_date !== null)
            ->map(fn (BloodRequest $r): float => $r->request_date->diffInMinutes($r->fulfilled_at) / 60);

        $released = RequestAllocation::query()
            ->whereBetween('released_at', [$from, $to])
            ->whereIn('request_id', BloodRequest::query()->where('target_facility_id', $facility->id)->select('id'))
            ->count();

        return [
            'donations' => $donations,
            'deferrals' => $deferrals,
            'requests' => [
                'received' => $requests->count(),
                'emergency' => $requests->filter(fn (BloodRequest $r): bool => $r->urgency_level === UrgencyLevel::Emergency)->count(),
                'fulfilled' => $fulfilled->count(),
                'partial' => $requests->filter(fn (BloodRequest $r): bool => $r->status === BloodRequestStatus::Partial)->count(),
                'open' => $requests->filter(fn (BloodRequest $r): bool => in_array($r->status, [BloodRequestStatus::Pending, BloodRequestStatus::Processing], true))->count(),
                'declined' => $requests->filter(fn (BloodRequest $r): bool => in_array($r->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true))->count(),
                'avg_hours_to_fulfil' => $hours->isEmpty() ? null : round($hours->avg(), 1),
            ],
            'units' => [
                'released' => $released,
                'expired' => BloodUnit::query()->where('facility_id', $facility->id)->whereBetween('expired_at', [$from, $to])->count(),
                'discarded' => BloodUnit::query()->where('facility_id', $facility->id)->whereBetween('discarded_at', [$from, $to])->count(),
            ],
            'daily' => array_values($days),
        ];
    }

    /**
     * Staff account changes and sign-ins over the period.
     *
     * @return array<string, mixed>
     */
    private function staff(Facility $facility, mixed $from, mixed $to): array
    {
        $staffActions = array_merge(...array_values(self::STAFF_GROUPS));
        $logs = $this->logs($facility, $from, $to)
            ->whereIn('action', [...$staffActions, 'auth.signed_in', 'auth.sign_in_failed'])
            ->get();

        $counts = array_map(
            fn (array $actions): int => $logs->whereIn('action', $actions)->count(),
            self::STAFF_GROUPS
        );

        $subjects = User::query()
            ->whereIn('id', $logs->where('auditable_type', User::class)->pluck('auditable_id')->unique())
            ->get()
            ->keyBy('id');

        return [
            ...$counts,
            'sign_ins' => $logs->where('action', 'auth.signed_in')->count(),
            'failed_sign_ins' => $logs->where('action', 'auth.sign_in_failed')->count(),
            'events' => $logs
                ->whereIn('action', $staffActions)
                ->sortByDesc('id')
                ->take(self::EVENTS)
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'at' => $log->created_at?->toISOString(),
                    'label' => AuditActions::describe($log->action)['label'],
                    'by' => $log->actor?->signatureName() ?? 'System',
                    'subject' => ($subject = $subjects->get((int) $log->auditable_id)) ? $subject->signatureName() : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * What was done in the period, by module and by person.
     *
     * @return array<string, mixed>
     */
    private function activity(Facility $facility, mixed $from, mixed $to): array
    {
        $logs = $this->logs($facility, $from, $to)->get(['id', 'actor_id', 'action']);

        $byModule = $logs
            ->groupBy(fn (AuditLog $log): string => AuditActions::describe($log->action)['module_label'])
            ->map(fn ($group, string $label): array => ['module' => $label, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();

        $actors = User::query()->whereIn('id', $logs->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        $byPerson = $logs
            ->whereNotNull('actor_id')
            ->groupBy('actor_id')
            ->map(fn ($group, $actorId): array => [
                'name' => $actors->get((int) $actorId)?->signatureName() ?? 'Former staff',
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();

        return ['total' => $logs->count(), 'by_module' => $byModule, 'top_people' => $byPerson];
    }

    private function logs(Facility $facility, mixed $from, mixed $to)
    {
        return AuditLog::query()
            ->with('actor')
            ->where('facility_id', $facility->id)
            ->whereBetween('created_at', [$from, $to]);
    }

    private function timezone(): string
    {
        return (string) config('blood_center.timezone', 'Asia/Manila');
    }

    private function dateOf(mixed $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone($this->timezone())->toDateString();
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
