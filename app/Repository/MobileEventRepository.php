<?php

namespace App\Repository;

use App\Enums\AppointmentStatus;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\MobileEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MobileEventRepository
{
    /**
     * Every drive a facility has run or scheduled, newest first.
     *
     * Unlike the donor-facing catalogue this deliberately keeps past drives:
     * the blood centre manages history here, and a drive that has happened is
     * still the record of what happened.
     *
     * @return Collection<int, MobileEvent>
     */
    public function forFacility(int $facilityId): Collection
    {
        return MobileEvent::query()
            ->with('facility')
            ->withCount(['appointments as registered' => fn ($query) => $query->active()])
            ->where('facility_id', $facilityId)
            ->orderByDesc('event_date')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): MobileEvent
    {
        $event = MobileEvent::query()->create($attributes);

        // The caller formats the fresh row alongside listed ones, which carry a
        // registered count from withCount(). A new drive has none, but the
        // attribute has to exist or status() would fall back to a query.
        $event->setAttribute('registered', 0);

        return $event->load('facility');
    }

    /**
     * One of the facility's drives, locked for the rest of the transaction.
     *
     * The same row lock bookings take (AppointmentRepository::lockDrive()), so
     * an edit or cancellation and a booking for one drive never interleave.
     */
    public function lockForFacility(int $eventId, int $facilityId): ?MobileEvent
    {
        return MobileEvent::query()
            ->where('facility_id', $facilityId)
            ->whereKey($eventId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Reload a drive for the response, with its live registered count.
     */
    public function fresh(int $eventId): MobileEvent
    {
        return MobileEvent::query()
            ->with('facility')
            ->withCount(['appointments as registered' => fn ($query) => $query->active()])
            ->whereKey($eventId)
            ->firstOrFail();
    }

    /**
     * How many of a drive's bookings are in each active status.
     *
     * @return array<string, int> keyed by AppointmentStatus value
     */
    public function activeCountsByStatus(int $eventId): array
    {
        return DonationAppointment::query()
            ->where('event_id', $eventId)
            ->active()
            ->get(['id', 'status'])
            ->countBy(fn (DonationAppointment $appointment): string => $appointment->status->value)
            ->all();
    }

    /**
     * A drive's bookings still waiting for their donor, with the donor's
     * account for the notice that follows a cancellation.
     *
     * @return Collection<int, DonationAppointment>
     */
    public function scheduledAppointments(int $eventId): Collection
    {
        return DonationAppointment::query()
            ->with('donorProfile.donor')
            ->where('event_id', $eventId)
            ->where('status', AppointmentStatus::Scheduled->value)
            ->get();
    }

    /**
     * Bags actually drawn at this facility's drives in the given month.
     *
     * A drive's donation is reached through the existing links only: its
     * booking (`donation_appointments.event_id`) or the paper sheet it was
     * typed from. "Drawn" means a collection was recorded; the donation's
     * status alone is not proof that blood reached a bag.
     */
    public function unitsCollectedAtDrivesInMonth(int $facilityId, Carbon $month): int
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('donation_date', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ])
            ->where(fn (Builder $query) => $query
                ->whereNotNull('paper_sheet_id')
                ->orWhereHas('appointment', fn (Builder $appointment) => $appointment->whereNotNull('event_id')))
            ->whereHas('collection')
            ->count();
    }
}
