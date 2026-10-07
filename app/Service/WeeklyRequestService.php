<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\ReplenishmentSchedule;
use App\Models\User;
use App\Models\WeeklyRequest;
use App\Repository\BloodRequestRepository;
use App\Repository\WeeklyRequestRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A hospital blood bank's weekly request: its scheduled restock from one blood centre.
 *
 * Sent only on one of the hospital's request days for that centre, at most
 * once a day. One weekly request covers several blood types, and a blood
 * request carries one, so it is written as one ordinary replenishment request
 * per blood type under a WR- header. The centre approves, reserves, bills and
 * dispatches each exactly as any replenishment, supplying what it can; when
 * it dispatches, whatever it did not supply is closed as unavailable
 * (FulfillmentService::release), because the next request day's order
 * replaces it.
 *
 * A routine restock can only be sent this way. A STAT restock is still raised
 * on its own, on any day (BloodRequestService::submit).
 */
class WeeklyRequestService
{
    /**
     * How many times submission will retry a reference-number collision.
     */
    private const REFERENCE_ATTEMPTS = 3;

    /**
     * How far back the status looks for request days that went without a request.
     */
    public const STATUS_WINDOW_DAYS = 28;

    public function __construct(
        private readonly WeeklyRequestRepository $repository,
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly BloodRequestService $bloodRequestService,
        private readonly ReplenishmentScheduleService $scheduleService,
        private readonly BloodRequestProjector $projector,
        private readonly BloodRequestNotifier $notifier,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Send a centre the caller hospital's weekly request for today.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submit(User $user, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);
        $target = $this->bloodRequestService->eligibleTarget((int) $payload['target_facility_id'], $facilityId);
        $today = OperationalDay::today();

        $schedule = $this->repository->scheduleFor($facilityId, $target->id)
            ?? throw $this->refuse(
                409,
                'no_request_schedule',
                "Set your request days for {$target->name} before sending it a weekly request."
            );

        if (! $schedule->includesWeekday($today->dayOfWeekIso)) {
            throw $this->refuse(
                409,
                'not_a_request_day',
                "Today is not one of your request days for {$target->name} ({$schedule->weekdaysLabel()}). "
                .'A patient who needs blood sooner is a Patient Transfusion Request.'
            );
        }

        $orders = $this->ordersByBloodType($payload);

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                [$weekly, $requests] = DB::transaction(
                    fn (): array => $this->persist($user, $facilityId, $target, $today->toDateString(), $orders)
                );

                // After the commit, never inside it: a notification failure
                // must not roll back an order the hospital was told was sent.
                foreach ($requests as $request) {
                    $this->notifier->targetFacility($request);
                }

                return [
                    'message' => 'Weekly request sent to '.$target->name.'.',
                    'weekly_request' => $this->format(
                        $this->repository->findRaisedBy($weekly->id, $facilityId),
                        withAllocations: true
                    ),
                ];
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw $this->refuse(
            409,
            'reference_generation_failed',
            'Could not allocate a request reference. Please try again.'
        );
    }

    /**
     * List the caller hospital's weekly requests, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repository
            ->paginateRaisedBy($this->requireFacilityId($user), $filters, $perPage)
            ->through(fn (WeeklyRequest $weekly): array => $this->format($weekly));
    }

    /**
     * Show one of the caller hospital's weekly requests, with every bag dispatched for it.
     *
     * @return array{weekly_request: array<string, mixed>}
     */
    public function show(User $user, int $weeklyRequestId): array
    {
        $weekly = $this->repository->findRaisedBy($weeklyRequestId, $this->requireFacilityId($user))
            ?? throw $this->refuse(404, 'weekly_request_not_found', 'Weekly request not found.');

        return ['weekly_request' => $this->format($weekly, withAllocations: true)];
    }

    /**
     * Where the caller hospital stands against its request days today.
     *
     * Derived, never stored: for each centre it keeps request days with,
     * whether today is one, whether today's request has gone, the next
     * request day, and the request days in the last four weeks with whether a
     * request was sent on each. A day before the schedule existed is not
     * counted as missed. Days are read against the schedule as it stands, so
     * changing the days changes which past days count.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $facilityId = $this->requireFacilityId($user);
        $today = OperationalDay::today()->startOfDay();
        $windowStart = $today->subDays(self::STATUS_WINDOW_DAYS)->toDateString();

        $sent = $this->repository->sentSince($facilityId, $windowStart)
            ->keyBy(fn (WeeklyRequest $weekly): string => $weekly->target_facility_id.'|'.$weekly->request_day->toDateString());

        return [
            'as_of' => $today->toDateString(),
            'today_weekday' => $today->dayOfWeekIso,
            'schedules' => $this->repository->schedulesFor($facilityId)
                ->map(fn (ReplenishmentSchedule $schedule): array => $this->scheduleStatus($schedule, $today, $windowStart, $sent))
                ->values()
                ->all(),
        ];
    }

    /**
     * Write the header and one replenishment per blood type under the hospital's lock.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $orders
     * @return array{0: WeeklyRequest, 1: array<int, BloodRequest>}
     */
    private function persist(User $user, int $facilityId, Facility $target, string $requestDay, array $orders): array
    {
        $this->bloodRequestRepository->lockFacility($facilityId)
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        // Checked under the lock, so two members of staff sending at once
        // cannot both pass it; the unique index stands behind it.
        if ($this->repository->existsFor($facilityId, $target->id, $requestDay)) {
            throw $this->refuse(
                409,
                'weekly_request_exists',
                "Today's weekly request to {$target->name} has already been sent."
            );
        }

        $weekly = WeeklyRequest::query()->create([
            'reference_number' => $this->repository->nextReference($facilityId),
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'request_day' => $requestDay,
            'requested_by' => $user->id,
        ]);

        $requests = [];

        foreach ($orders as $bloodTypeId => $items) {
            $requests[] = $this->bloodRequestService->writeReplenishment(
                $user,
                $facilityId,
                $target,
                $bloodTypeId,
                UrgencyLevel::Routine,
                $items,
                $weekly->id
            );
        }

        $this->auditLogger->record($user, 'weekly_request.submitted', $weekly, [
            'facility_id' => $facilityId,
            'target_facility_id' => $target->id,
            'reference_number' => $weekly->reference_number,
            'request_day' => $requestDay,
            'requests' => array_map(fn (BloodRequest $request): string => $request->reference_number, $requests),
            'quantity' => array_sum(array_map(fn (BloodRequest $request): int => $request->quantity, $requests)),
        ]);

        return [$weekly, $requests];
    }

    /**
     * Group the submitted lines into one list of components per blood type.
     *
     * A blood request carries a single blood type, so each becomes its own
     * request. There is no indication: a weekly request restocks the shelves
     * and has no patient to certify one for.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function ordersByBloodType(array $payload): array
    {
        $orders = [];

        foreach ($payload['lines'] as $line) {
            $orders[(int) $line['blood_type_id']][] = [
                'component_id' => (int) $line['component_id'],
                'quantity' => (int) $line['quantity'],
            ];
        }

        ksort($orders);

        return $orders;
    }

    /**
     * Where one schedule stands today.
     *
     * @param  Collection<string, WeeklyRequest>  $sent
     * @return array<string, mixed>
     */
    private function scheduleStatus(ReplenishmentSchedule $schedule, CarbonImmutable $today, string $windowStart, Collection $sent): array
    {
        $todayDate = $today->toDateString();
        $centreId = $schedule->target_facility_id;
        $sentToday = $sent->get("{$centreId}|{$todayDate}");
        $isRequestDay = $schedule->includesWeekday($today->dayOfWeekIso);

        // Request days only count as missed once the schedule existed.
        $countFrom = max($windowStart, $schedule->created_at ? OperationalDay::dateOf($schedule->created_at) : $todayDate);

        $recent = [];

        for ($day = $today->subDay(); $day->toDateString() >= $countFrom; $day = $day->subDay()) {
            if (! $schedule->includesWeekday($day->dayOfWeekIso)) {
                continue;
            }

            $weekly = $sent->get("{$centreId}|{$day->toDateString()}");

            $recent[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->dayOfWeekIso,
                'sent' => $weekly !== null,
                'weekly_request' => $weekly === null ? null : ['id' => $weekly->id, 'reference_number' => $weekly->reference_number],
            ];
        }

        $missed = array_values(array_filter($recent, fn (array $day): bool => ! $day['sent']));

        $nextRequestDay = null;

        for ($offset = 1; $offset <= 7; $offset++) {
            $candidate = $today->addDays($offset);

            if ($schedule->includesWeekday($candidate->dayOfWeekIso)) {
                $nextRequestDay = $candidate->toDateString();

                break;
            }
        }

        return [
            'schedule' => $this->scheduleService->format($schedule),
            'is_request_day' => $isRequestDay,
            'sent_today' => $sentToday === null ? null : ['id' => $sentToday->id, 'reference_number' => $sentToday->reference_number],
            'due_today' => $isRequestDay && $sentToday === null,
            'next_request_day' => $nextRequestDay,
            'recent' => $recent,
            'missed_count' => count($missed),
            'last_missed_day' => $missed[0]['date'] ?? null,
        ];
    }

    /**
     * Project a weekly request for the API: its header, its blood requests, and what came of them.
     *
     * `not_supplied` counts only what can no longer come: the remainder of a
     * request that is closed, rejected or cancelled. What an open request has
     * still to supply is not yet short.
     *
     * @return array<string, mixed>
     */
    public function format(WeeklyRequest $weekly, bool $withAllocations = false): array
    {
        $requests = $weekly->bloodRequests
            ->map(fn (BloodRequest $request): array => $this->projector->project($request, $withAllocations))
            ->values();

        $requested = (int) $requests->sum('quantity');
        $fulfilled = (int) $requests->sum('fulfilled_quantity');
        $received = (int) $requests->sum('received_count');
        $notSupplied = (int) $requests
            ->filter(fn (array $request): bool => ! $request['is_open'])
            ->sum('remaining_quantity');

        [$status, $label] = $this->overallStatus($requests, $requested, $fulfilled);
        $requester = $weekly->requester;
        $centre = $weekly->targetFacility;

        return [
            'id' => $weekly->id,
            'reference_number' => $weekly->reference_number,
            'request_day' => $weekly->request_day->toDateString(),
            'submitted_at' => $weekly->created_at?->toIso8601String(),
            'requester_name' => $requester === null ? null : trim($requester->first_name.' '.$requester->last_name),
            'target_facility' => $centre === null ? null : [
                'id' => $centre->id,
                'name' => $centre->name,
                'address' => $centre->address,
            ],
            'status' => $status,
            'status_label' => $label,
            'is_open' => $requests->contains(fn (array $request): bool => $request['is_open']),
            'totals' => [
                'requested' => $requested,
                'fulfilled' => $fulfilled,
                'received' => $received,
                'awaiting_receipt' => max(0, $fulfilled - $received),
                'not_supplied' => $notSupplied,
            ],
            'requests' => $requests->all(),
        ];
    }

    /**
     * Sum up the blood requests' statuses into one for the weekly request.
     *
     * @param  Collection<int, array<string, mixed>>  $requests
     * @return array{0: string, 1: string}
     */
    private function overallStatus(Collection $requests, int $requested, int $fulfilled): array
    {
        if ($requests->every(fn (array $request): bool => $request['status'] === BloodRequestStatus::Pending->value)) {
            return ['submitted', 'Submitted'];
        }

        if ($requests->contains(fn (array $request): bool => $request['is_open'])) {
            return ['in_progress', 'In progress'];
        }

        if ($fulfilled === 0) {
            return ['not_supplied', 'Not supplied'];
        }

        return $fulfilled >= $requested
            ? ['fulfilled', 'Fulfilled']
            : ['partial', 'Partially fulfilled'];
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Whether a query failure is a unique-index collision.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
