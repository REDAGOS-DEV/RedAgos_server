<?php

namespace App\Service;

use App\Models\Facility;
use App\Models\ReplenishmentSchedule;
use App\Models\User;
use App\Repository\WeeklyRequestRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * A hospital blood bank's request days: which weekdays it sends each centre its weekly request.
 *
 * The hospital sets its own days, per centre. Changing them takes effect at
 * once — a day added today makes today a request day — and every change is
 * audited with the days before and after, because the schedule is what
 * decides whether a weekly request may be sent.
 */
class ReplenishmentScheduleService
{
    public function __construct(
        private readonly WeeklyRequestRepository $repository,
        private readonly BloodRequestService $bloodRequestService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * List the caller hospital's request schedules.
     *
     * @return array{schedules: array<int, array<string, mixed>>}
     */
    public function list(User $user): array
    {
        return [
            'schedules' => $this->repository->schedulesFor($this->requireFacilityId($user))
                ->map(fn (ReplenishmentSchedule $schedule): array => $this->format($schedule))
                ->values()
                ->all(),
        ];
    }

    /**
     * Set the days the caller hospital sends one centre its weekly request.
     *
     * The centre must be one a request may be addressed to; the same rule a
     * request itself is held to (BloodRequestService::eligibleTarget).
     *
     * @param  array<int, int>  $days
     * @return array<string, mixed>
     */
    public function save(User $user, int $targetFacilityId, array $days): array
    {
        $facilityId = $this->requireFacilityId($user);
        $target = $this->bloodRequestService->eligibleTarget($targetFacilityId, $facilityId);

        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        $schedule = DB::transaction(function () use ($user, $facilityId, $target, $days): ReplenishmentSchedule {
            $schedule = $this->repository->scheduleFor($facilityId, $target->id)
                ?? new ReplenishmentSchedule(['facility_id' => $facilityId, 'target_facility_id' => $target->id]);

            $previous = $schedule->exists ? $schedule->weekdays() : null;

            $schedule->days_of_week = $days;
            $schedule->updated_by = $user->id;
            $schedule->save();

            $this->auditLogger->record($user, 'replenishment_schedule.saved', $schedule, [
                'facility_id' => $facilityId,
                'target_facility_id' => $target->id,
                'previous_days' => $previous,
                'days_of_week' => $days,
            ]);

            return $schedule;
        });

        return [
            'message' => "Request days for {$target->name} saved.",
            'schedule' => $this->format($this->repository->scheduleFor($facilityId, $schedule->target_facility_id)),
        ];
    }

    /**
     * Stop keeping request days for one centre.
     *
     * Weekly requests already sent to it are untouched. Without a schedule
     * the hospital can send that centre no weekly request until it sets one
     * again.
     *
     * @return array{message: string}
     */
    public function delete(User $user, int $targetFacilityId): array
    {
        $facilityId = $this->requireFacilityId($user);

        $schedule = $this->repository->scheduleFor($facilityId, $targetFacilityId)
            ?? throw $this->refuse(404, 'schedule_not_found', 'You keep no request days for that blood center.');

        DB::transaction(function () use ($user, $facilityId, $schedule): void {
            $this->auditLogger->record($user, 'replenishment_schedule.deleted', $schedule, [
                'facility_id' => $facilityId,
                'target_facility_id' => $schedule->target_facility_id,
                'previous_days' => $schedule->weekdays(),
            ]);

            $schedule->delete();
        });

        return ['message' => 'Request days removed.'];
    }

    /**
     * Project a schedule for the API.
     *
     * @return array<string, mixed>
     */
    public function format(ReplenishmentSchedule $schedule): array
    {
        /** @var Facility|null $centre */
        $centre = $schedule->targetFacility;
        $updatedBy = $schedule->updatedBy;

        return [
            'id' => $schedule->id,
            'target_facility' => $centre === null ? null : [
                'id' => $centre->id,
                'name' => $centre->name,
                'address' => $centre->address,
            ],
            'days_of_week' => $schedule->weekdays(),
            'days_label' => $schedule->weekdaysLabel(),
            'updated_by' => $updatedBy === null ? null : trim($updatedBy->first_name.' '.$updatedBy->last_name),
            'created_at' => $schedule->created_at?->toIso8601String(),
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
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
