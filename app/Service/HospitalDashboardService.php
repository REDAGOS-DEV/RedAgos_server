<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\HospitalUnitStatus;
use App\Enums\UntagReason;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\User;
use App\Repository\HospitalInventoryRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The hospital blood bank's dashboard (project owner, 2026-11-01): counts for
 * the signed-in staff member's own facility, from the requests it raised and
 * the bags on its shelf. Each part is read only for a role that may open the
 * page behind it, so a requesting-only account sees no stock figures.
 */
class HospitalDashboardService
{
    private const RECENT_REQUESTS = 6;

    private const RECENT_ACTIVITIES = 6;

    private const RECENT_NOTIFICATIONS = 4;

    private const ACTIVITY_LABELS = [
        'received' => 'received',
        'stocked' => 'stocked in',
        'tagged' => 'tagged to a patient',
        'crossmatched' => 'crossmatched',
        'transfused' => 'transfused',
        'untagged' => 'untagged',
        'returned' => 'returned to storage',
        'discarded' => 'discarded',
        'expired' => 'expired',
    ];

    public function __construct(
        private readonly HospitalInventoryRepository $inventory,
        private readonly HospitalInventoryService $inventoryService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $facilityId = (int) $user->facility_id;
        $abilities = $user->abilities();
        $seesRequests = in_array('hospital.requests.view', $abilities, true);
        $seesStock = in_array('hospital.inventory.view', $abilities, true);

        return [
            'units_available' => $seesStock ? $this->inventory->summaryCounts($facilityId)[HospitalUnitStatus::Available->value] : 0,
            // Bags left past their 24 hours (project owner, 2026-11-07). A tag
            // staff released in time is neither, whatever its status says.
            'untagged_crossmatched' => $seesStock ? $this->inventory->endedTagCount($facilityId, UntagReason::TransfusionDeadlineExpired) : 0,
            'untagged_assigned' => $seesStock ? $this->inventory->endedTagCount($facilityId, UntagReason::CrossmatchDeadlineExpired) : 0,
            ...($seesRequests ? $this->requestFigures($facilityId) : $this->noRequestFigures()),
            'activities' => $this->activities($user, $facilityId, $seesRequests, $seesStock),
            'notifications' => $this->notifications($user),
            'tasks' => $this->tasks($facilityId, $seesRequests, $seesStock),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFigures(int $facilityId): array
    {
        [$dayStart, $dayEnd] = OperationalDay::boundsFor();
        $raised = fn (): Builder => BloodRequest::query()->raisedBy($facilityId);

        return [
            'pending_requests' => $raised()->where('status', BloodRequestStatus::Pending)->count(),
            'approved_requests' => $raised()->whereIn('status', [BloodRequestStatus::Processing, BloodRequestStatus::Partial])->count(),
            'completed_today' => $raised()->where('status', BloodRequestStatus::Fulfilled)->whereBetween('updated_at', [$dayStart, $dayEnd])->count(),
            'emergency_requests' => $raised()->open()->where('urgency_level', UrgencyLevel::Emergency)->count(),
            'recent_requests' => $this->recentRequests($facilityId),
            ...$this->trends($facilityId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noRequestFigures(): array
    {
        return [
            'pending_requests' => 0,
            'approved_requests' => 0,
            'completed_today' => 0,
            'emergency_requests' => 0,
            'recent_requests' => [],
            'weekly_trend' => [],
            'monthly_trend' => [],
            'yearly_trend' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentRequests(int $facilityId): array
    {
        return BloodRequest::query()
            ->raisedBy($facilityId)
            ->with(['items.bloodType', 'items.component'])
            ->latest('request_date')
            ->latest('id')
            ->limit(self::RECENT_REQUESTS)
            ->get()
            ->map(fn (BloodRequest $request): array => [
                'id' => $request->id,
                'reference_number' => $request->reference_number,
                'blood_type' => $request->items->map(fn (BloodRequestItem $item): ?string => $item->bloodType?->code)->filter()->unique()->implode(', ') ?: '—',
                'quantity' => (int) $request->items->sum('quantity'),
                'component_name' => $request->items->map(fn (BloodRequestItem $item): ?string => $item->component?->name)->filter()->unique()->implode(', ') ?: '—',
                'date_label' => $this->local($request->request_date)->format('M j, Y'),
                'status' => $request->status->value,
            ])
            ->all();
    }

    /**
     * Requests raised: the last 7 days, the last 5 weeks, the last 12 months.
     *
     * @return array<string, array<int, array{label: string, value: int}>>
     */
    private function trends(int $facilityId): array
    {
        $today = OperationalDay::today();
        $year = $today->subMonthsNoOverflow(11)->startOfMonth();

        $dates = BloodRequest::query()
            ->raisedBy($facilityId)
            ->where('request_date', '>=', $year->utc())
            ->pluck('request_date')
            ->map(fn (mixed $at): CarbonImmutable => $this->local($at));

        $count = fn (CarbonImmutable $from, CarbonImmutable $until): int => $dates
            ->filter(fn (CarbonImmutable $at): bool => $at->gte($from) && $at->lt($until))
            ->count();

        $days = Collection::times(7, function (int $n) use ($today, $count): array {
            $from = $today->subDays(7 - $n)->startOfDay();

            return ['label' => $from->format('D'), 'value' => $count($from, $from->addDay())];
        });

        $weeks = Collection::times(5, function (int $n) use ($today, $count): array {
            $from = $today->startOfWeek()->subWeeks(5 - $n);

            return ['label' => $from->format('M j'), 'value' => $count($from, $from->addWeek())];
        });

        $months = Collection::times(12, function (int $n) use ($today, $count): array {
            $from = $today->startOfMonth()->subMonthsNoOverflow(12 - $n);

            return ['label' => $from->format('M'), 'value' => $count($from, $from->addMonthNoOverflow())];
        });

        return [
            'weekly_trend' => $days->all(),
            'monthly_trend' => $weeks->all(),
            'yearly_trend' => $months->all(),
        ];
    }

    /**
     * Today at this blood bank, newest first: requests raised and bags moved.
     *
     * @return array<int, array{time: string, label: string}>
     */
    private function activities(User $user, int $facilityId, bool $seesRequests, bool $seesStock): array
    {
        [$dayStart, $dayEnd] = OperationalDay::boundsFor();
        $rows = collect();

        if ($seesRequests) {
            $rows = $rows->concat(BloodRequest::query()
                ->raisedBy($facilityId)
                ->whereBetween('request_date', [$dayStart, $dayEnd])
                ->latest('request_date')
                ->limit(self::RECENT_ACTIVITIES)
                ->get()
                ->map(fn (BloodRequest $request): array => [
                    'at' => $this->local($request->request_date),
                    'label' => "Request {$request->reference_number} raised",
                ]));
        }

        if ($seesStock) {
            $rows = $rows->concat(collect($this->inventoryService->activity($user, null, self::RECENT_ACTIVITIES)->items())
                ->filter(fn (array $entry): bool => $entry['at'] !== null && $this->local($entry['at'])->betweenIncluded($dayStart, $dayEnd))
                ->map(fn (array $entry): array => [
                    'at' => $this->local($entry['at']),
                    'label' => 'Bag '.$entry['bag_number'].' '.(self::ACTIVITY_LABELS[$entry['kind']] ?? $entry['kind']),
                ]));
        }

        return $rows
            ->sortByDesc(fn (array $row): int => $row['at']->getTimestamp())
            ->take(self::RECENT_ACTIVITIES)
            ->map(fn (array $row): array => ['time' => $row['at']->format('g:i A'), 'label' => $row['label']])
            ->values()
            ->all();
    }

    /**
     * What has not been read yet, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function notifications(User $user): array
    {
        return $user->unreadNotifications()
            ->limit(self::RECENT_NOTIFICATIONS)
            ->get()
            ->map(fn (mixed $notification): array => [
                'message' => $notification->data['title'] ?? $notification->data['desc'] ?? 'New notification',
                'time' => $notification->created_at?->diffForHumans(),
                'icon' => $notification->data['icon'] ?? 'bell',
                'tone' => $notification->data['tone'] ?? null,
            ])
            ->all();
    }

    /**
     * What waits for this person now, for the dashboard's "Needs you".
     *
     * @return array<string, int>
     */
    private function tasks(int $facilityId, bool $seesRequests, bool $seesStock): array
    {
        $today = OperationalDay::todayAsDate();

        return [
            'to_receive' => $seesRequests
                ? BloodRequest::query()->raisedBy($facilityId)->whereHas('allocations', fn (Builder $allocations): Builder => $allocations->released()->whereNull('received_at'))->count()
                : 0,
            'to_stock_in' => $seesStock ? $this->inventory->summaryCounts($facilityId)[HospitalUnitStatus::Received->value] : 0,
            'expiring' => $seesStock ? $this->inventory->expiringWithinCount($facilityId, $today, 3) : 0,
            'overdue_tags' => $seesStock ? $this->inventory->overdueActiveTagCount($facilityId, now()->toImmutable()) : 0,
        ];
    }

    /** An instant in the blood bank's own day. */
    private function local(mixed $at): CarbonImmutable
    {
        return Date::parse($at)->toImmutable()->setTimezone(OperationalDay::today()->getTimezone());
    }
}
