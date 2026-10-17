<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Enums\FacilityDonorStatus;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Repository\CollectionDashboardRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\DonorStanding;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The Donor/Collection department's dashboard, for one centre (owner,
 * 2026-10-16): today at the counter, the donor pool, the coming week, and the
 * last 30 days of donations.
 *
 * Counts only — no donor is named. It is read by every role at the counter
 * (donations.view), including the collection chair, who holds no donor record
 * access, so nothing here may identify a person.
 *
 * The donor pool reads DonorStanding, the same reading as the Donors list and
 * the eligibility reminder, so "eligible" means the same thing on all three.
 */
class CollectionDashboardService
{
    private const TREND_DAYS = 30;

    private const UPCOMING_DAYS = 7;

    private const POOL_CHUNK = 500;

    public function __construct(
        private readonly CollectionDashboardRepository $collectionDashboardRepository,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly DonorStandingResolver $donorStandingResolver
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $staff): array
    {
        $facility = $this->requireFacility($staff);
        $today = OperationalDay::today()->startOfDay();
        [$dayStart, $dayEnd] = OperationalDay::boundsFor();

        return [
            'as_of' => OperationalDay::today()->toIso8601String(),
            'today' => [
                'date' => $today->toDateString(),
                'appointments' => $this->appointmentsToday($facility, $dayStart, $dayEnd),
                'donations' => $this->collectionDashboardRepository->donationStagesBetween($facility->id, $dayStart, $dayEnd),
            ],
            'pool' => $this->pool($facility),
            'upcoming' => $this->upcoming($facility, $today),
            'trend' => $this->trend($facility, $today),
        ];
    }

    /**
     * @return array{total: int, waiting: int, in_progress: int, collected: int, no_show: int, cancelled: int}
     */
    private function appointmentsToday(Facility $facility, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = $this->collectionDashboardRepository->appointmentCountsBetween($facility->id, $from, $to);

        return [
            'total' => array_sum($counts),
            // Booked and not yet arrived: the counter's queue.
            'waiting' => $counts[AppointmentStatus::Scheduled->value] ?? 0,
            'in_progress' => $counts[AppointmentStatus::Confirmed->value] ?? 0,
            'collected' => $counts[AppointmentStatus::Completed->value] ?? 0,
            'no_show' => $counts[AppointmentStatus::NoShow->value] ?? 0,
            'cancelled' => $counts[AppointmentStatus::Cancelled->value] ?? 0,
        ];
    }

    /**
     * Every donor on this centre's list, by where they stand.
     *
     * Inactive donors are counted once, as inactive, whatever their
     * eligibility: they are out of the pool until a centre marks them active.
     * The blood-group split is of the donors who could be called in today.
     *
     * @return array<string, mixed>
     */
    private function pool(Facility $facility): array
    {
        $pool = ['total' => 0, 'eligible' => 0, 'in_progress' => 0, 'waiting' => 0, 'deferred' => 0, 'inactive' => 0, 'new_this_week' => 0];
        $byType = [];
        $weekAgo = now()->subDays(7);

        $this->donorDirectoryRepository
            ->facilityDonorQuery($facility->id)
            ->reorder()
            ->chunkById(self::POOL_CHUNK, function ($donors) use ($facility, $weekAgo, &$pool, &$byType): void {
                $standings = $this->donorStandingResolver->forDonors($facility->id, $donors);
                $records = $this->donorDirectoryRepository->facilityRecordsFor($facility->id, $donors->pluck('id')->all());

                foreach ($donors as $donor) {
                    $pool['total']++;

                    if ($donor->created_at?->isAfter($weekAgo)) {
                        $pool['new_this_week']++;
                    }

                    if (($records[$donor->id] ?? null)?->status === FacilityDonorStatus::Inactive) {
                        $pool['inactive']++;

                        continue;
                    }

                    $eligibility = $standings[$donor->id]->eligibility();
                    $pool[$eligibility]++;

                    if ($eligibility === DonorStanding::ELIGIBLE) {
                        $code = $donor->donorProfile?->bloodType?->code ?? 'unknown';
                        $byType[$code] = ($byType[$code] ?? 0) + 1;
                    }
                }
            }, 'users.id', 'id');

        $unknown = $byType['unknown'] ?? 0;
        unset($byType['unknown']);

        return [
            ...$pool,
            'eligible_by_blood_type' => collect($byType)
                ->map(fn (int $count, string $code): array => ['code' => $code, 'count' => $count])
                ->values()
                ->all(),
            'eligible_untyped' => $unknown,
        ];
    }

    /**
     * The next seven days, starting tomorrow: today has its own section.
     *
     * @return array{days: array<int, array{date: string, appointments: int}>, drives: array<int, array<string, mixed>>}
     */
    private function upcoming(Facility $facility, CarbonImmutable $today): array
    {
        $first = $today->addDay();
        $last = $today->addDays(self::UPCOMING_DAYS);
        [$from] = OperationalDay::boundsFor($first->toDateString());
        [, $to] = OperationalDay::boundsFor($last->toDateString());

        $perDay = $this->collectionDashboardRepository
            ->activeAppointmentTimesBetween($facility->id, $from, $to)
            ->countBy(fn ($at) => $this->operationalDate($at));

        $days = [];

        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'appointments' => (int) ($perDay[$day->toDateString()] ?? 0)];
        }

        // Drives from today, so one running today is still listed.
        $drives = $this->collectionDashboardRepository
            ->drivesBetween($facility->id, $today->toDateString(), $last->toDateString())
            ->map(fn (MobileEvent $drive): array => [
                'id' => $drive->id,
                'name' => $drive->name,
                'location' => $drive->location,
                'event_date' => $drive->event_date?->toDateString(),
                'start_time' => $drive->start_time,
                'end_time' => $drive->end_time,
                'max_capacity' => $drive->max_capacity,
                'registered' => (int) $drive->registered,
            ])
            ->values()
            ->all();

        return ['days' => $days, 'drives' => $drives];
    }

    /**
     * Donations and deferrals per day for the last 30 days, today included.
     *
     * A donation is blood given, the same count as the Donors list's total. A
     * deferral is a visit the screening physician deferred.
     *
     * @return array<int, array{date: string, donations: int, deferrals: int}>
     */
    private function trend(Facility $facility, CarbonImmutable $today): array
    {
        $first = $today->subDays(self::TREND_DAYS - 1);
        [$from] = OperationalDay::boundsFor($first->toDateString());
        [, $to] = OperationalDay::boundsFor($today->toDateString());

        $buckets = [];

        for ($day = $first; $day->lte($today); $day = $day->addDay()) {
            $buckets[$day->toDateString()] = ['date' => $day->toDateString(), 'donations' => 0, 'deferrals' => 0];
        }

        foreach ($this->collectionDashboardRepository->visitsBetween($facility->id, $from, $to) as $visit) {
            $date = $this->operationalDate($visit->donation_date);

            if (! isset($buckets[$date])) {
                continue;
            }

            match ($this->collectionDashboardRepository->stageOf($visit)) {
                'collected' => $buckets[$date]['donations']++,
                'deferred' => $buckets[$date]['deferrals']++,
                default => null,
            };
        }

        return array_values($buckets);
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
