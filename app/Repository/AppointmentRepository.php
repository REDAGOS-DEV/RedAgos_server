<?php

namespace App\Repository;

use App\Enums\AppointmentStatus;
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
     * List upcoming mobile blood drives open for registration, with their
     * pre-registered donors counts. Drafts, cancelled drives and drives
     * closed to new bookings are left out.
     *
     * @return Collection<int, MobileEvent>
     */
    public function upcomingDrives(): Collection
    {
        return MobileEvent::query()
            ->with('facility')
            ->openForRegistration()
            ->withPreRegisteredCounts()
            ->whereDate('event_date', '>=', now()->toDateString())
            ->orderBy('event_date')
            ->get();
    }

    /**
     * Find an upcoming drive open for registration by id.
     */
    public function findUpcomingDrive(int $eventId): ?MobileEvent
    {
        return MobileEvent::query()
            ->openForRegistration()
            ->whereDate('event_date', '>=', now()->toDateString())
            ->whereKey($eventId)
            ->first();
    }

    /**
     * Lock a drive's own row for the rest of the transaction.
     *
     * Every booking, edit and cancellation of one drive queues behind this
     * lock, so no booking lands after the drive is cancelled or closed.
     */
    public function lockDrive(int $eventId): ?MobileEvent
    {
        return MobileEvent::query()->whereKey($eventId)->lockForUpdate()->first();
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
            // Walk-ins only. A mobile drive is stored against its host facility
            // at a nominal 09:00, so without this a drive's registrations would
            // eat that centre's 09:00 counter slot — and slot_capacity defaults
            // to 4, so one drive would close the morning to walk-ins.
            ->whereNull('event_id')
            ->active()
            ->whereBetween('appointment_datetime', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->pluck('appointment_datetime')
            ->groupBy(fn ($datetime): string => Carbon::parse($datetime)->format('H:i'))
            ->map(fn (Collection $rows): int => $rows->count());
    }

    /**
     * Count active bookings for one specific slot, locking the rows for update.
     *
     * PostgreSQL rejects FOR UPDATE alongside an aggregate (SQLSTATE 0A000), so
     * the locked rows are selected and counted in PHP instead of via count(*).
     */
    public function lockedSlotCount(int $facilityId, Carbon $slot): int
    {
        return DonationAppointment::query()
            ->where('facility_id', $facilityId)
            ->where('appointment_datetime', $slot)
            // Matches bookedCountsByTime(): counter capacity counts walk-ins;
            // a drive has no booking limit (project owner, 2026-11-12).
            ->whereNull('event_id')
            ->active()
            ->lockForUpdate()
            ->get(['id'])
            ->count();
    }

    /**
     * A donor's booking for a drive that is still live: not cancelled and not
     * a no-show. Walk-in or not, the latest.
     */
    public function liveDriveBooking(int $eventId, int $donorId): ?DonationAppointment
    {
        return DonationAppointment::query()
            ->where('event_id', $eventId)
            ->where('donor_id', $donorId)
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->latest('id')
            ->first();
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
