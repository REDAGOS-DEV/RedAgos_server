<?php

namespace App\Repository;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\MobileEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The counts behind the Donor/Collection dashboard, all for one facility.
 *
 * Timestamps are compared against absolute bounds (OperationalDay::boundsFor()),
 * never with whereDate(), and rows that need grouping by day are returned for
 * the service to bucket in the operational timezone — see OperationalDay for
 * the eight hours that otherwise go missing.
 */
class CollectionDashboardRepository
{
    /**
     * Appointments booked here within the bounds, counted by status.
     *
     * @return array<string, int> keyed by AppointmentStatus value
     */
    public function appointmentCountsBetween(int $facilityId, CarbonInterface $from, CarbonInterface $to): array
    {
        return DonationAppointment::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('appointment_datetime', [$from, $to])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->mapWithKeys(fn ($count, $status): array => [
                ($status instanceof AppointmentStatus ? $status->value : (string) $status) => (int) $count,
            ])
            ->all();
    }

    /**
     * Where the visits opened here within the bounds have got to.
     *
     * A visit is counted once, at the furthest point it reached: deferred at
     * screening, blood drawn, being screened, or registered and waiting.
     *
     * @return array{total: int, registered: int, screening: int, collected: int, deferred: int, closed: int}
     */
    public function donationStagesBetween(int $facilityId, CarbonInterface $from, CarbonInterface $to): array
    {
        $visits = $this->visitsBetween($facilityId, $from, $to);

        $stages = ['total' => $visits->count(), 'registered' => 0, 'screening' => 0, 'collected' => 0, 'deferred' => 0, 'closed' => 0];

        foreach ($visits as $visit) {
            $stages[$this->stageOf($visit)]++;
        }

        return $stages;
    }

    /**
     * The visits opened here within the bounds, with what the trend needs to
     * classify them. The service buckets them by operational day.
     *
     * @return Collection<int, Donation>
     */
    public function visitsBetween(int $facilityId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('donation_date', [$from, $to])
            ->with('screening:id,donation_id,outcome')
            ->withExists('collection')
            ->get(['id', 'donation_date', 'status']);
    }

    /**
     * registered, screening, collected, deferred, or closed (ended another way).
     */
    public function stageOf(Donation $visit): string
    {
        return match (true) {
            $visit->screening?->outcome instanceof ScreeningOutcome && $visit->screening->outcome->isDeferral() => 'deferred',
            (bool) $visit->collection_exists || $visit->status === DonationStatus::Completed => 'collected',
            $visit->status === DonationStatus::Registered => 'registered',
            $visit->status === DonationStatus::Screening => 'screening',
            default => 'closed',
        };
    }

    /**
     * The times of the appointments still holding a slot here within the bounds.
     *
     * @return \Illuminate\Support\Collection<int, Carbon>
     */
    public function activeAppointmentTimesBetween(int $facilityId, CarbonInterface $from, CarbonInterface $to): \Illuminate\Support\Collection
    {
        return DonationAppointment::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', AppointmentStatus::activeValues())
            ->whereBetween('appointment_datetime', [$from, $to])
            ->pluck('appointment_datetime');
    }

    /**
     * This facility's drives on or between two dates, with how many donors
     * still hold a place on each.
     *
     * @return Collection<int, MobileEvent>
     */
    public function drivesBetween(int $facilityId, string $fromDate, string $toDate): Collection
    {
        return MobileEvent::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('event_date', [$fromDate, $toDate])
            ->withCount(['appointments as registered' => fn (Builder $q) => $q->whereIn('status', AppointmentStatus::activeValues())])
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get();
    }
}
