<?php

namespace App\Repository;

use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\MobileEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AppointmentRepository
{
    /**
     * List blood centres currently open to donor bookings.
     *
     * @return Collection<int, Facility>
     */
    public function bookableCenters(): Collection
    {
        return Facility::query()
            ->acceptingDonations()
            ->whereHas('facilityType', fn ($query) => $query->where('name', 'blood_center'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a bookable centre by id.
     */
    public function findBookableCenter(int $facilityId): ?Facility
    {
        return Facility::query()
            ->acceptingDonations()
            ->whereKey($facilityId)
            ->first();
    }

    /**
     * List upcoming mobile blood drives with their registered counts.
     *
     * @return Collection<int, MobileEvent>
     */
    public function upcomingDrives(): Collection
    {
        return MobileEvent::query()
            ->with('facility')
            ->withCount(['appointments as registered' => fn ($query) => $query->active()])
            ->whereDate('event_date', '>=', now()->toDateString())
            ->orderBy('event_date')
            ->get();
    }

    /**
     * Find an upcoming drive by id, with its registered count loaded.
     */
    public function findUpcomingDrive(int $eventId): ?MobileEvent
    {
        return MobileEvent::query()
            ->withCount(['appointments as registered' => fn ($query) => $query->active()])
            ->whereDate('event_date', '>=', now()->toDateString())
            ->whereKey($eventId)
            ->first();
    }

    /**
     * Count active bookings per appointment time for a centre on a given date.
     *
     * @return Collection<string, int>
     */
    public function bookedCountsByTime(int $facilityId, Carbon $date): Collection
    {
        return DonationAppointment::query()
            ->where('facility_id', $facilityId)
            ->active()
            ->whereBetween('appointment_datetime', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->pluck('appointment_datetime')
            ->groupBy(fn ($datetime): string => Carbon::parse($datetime)->format('H:i'))
            ->map(fn (Collection $rows): int => $rows->count());
    }

    /**
     * Count active bookings for one specific slot, locking the rows for update.
     */
    public function lockedSlotCount(int $facilityId, Carbon $slot): int
    {
        return DonationAppointment::query()
            ->where('facility_id', $facilityId)
            ->where('appointment_datetime', $slot)
            ->active()
            ->lockForUpdate()
            ->count();
    }

    /**
     * Count active registrations for a drive, locking the rows for update.
     */
    public function lockedDriveCount(int $eventId): int
    {
        return DonationAppointment::query()
            ->where('event_id', $eventId)
            ->active()
            ->lockForUpdate()
            ->count();
    }

    /**
     * Determine whether the donor already holds an active future booking.
     */
    public function hasActiveAppointment(int $donorId, ?int $excludingId = null): bool
    {
        return DonationAppointment::query()
            ->where('donor_id', $donorId)
            ->active()
            ->where('appointment_datetime', '>=', now())
            ->when($excludingId, fn ($query) => $query->whereKeyNot($excludingId))
            ->exists();
    }

    /**
     * List the donor's own appointments, newest first.
     *
     * @return Collection<int, DonationAppointment>
     */
    public function forDonor(int $donorId): Collection
    {
        return DonationAppointment::query()
            ->with(['facility', 'mobileEvent'])
            ->where('donor_id', $donorId)
            ->orderByDesc('appointment_datetime')
            ->get();
    }

    /**
     * Find one appointment by id without scoping it to a donor.
     */
    public function find(int $id): ?DonationAppointment
    {
        return DonationAppointment::with(['facility', 'mobileEvent'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): DonationAppointment
    {
        return DonationAppointment::create($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(DonationAppointment $appointment, array $payload): DonationAppointment
    {
        $appointment->update($payload);

        return $appointment;
    }
}
