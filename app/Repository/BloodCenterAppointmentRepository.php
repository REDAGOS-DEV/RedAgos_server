<?php

namespace App\Repository;

use App\Models\DonationAppointment;
use App\Models\MobileEvent;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * One centre's appointments as the Appointments page lists them: walk-ins at
 * the centre and registrations for its mobile blood drives alike.
 */
class BloodCenterAppointmentRepository
{
    private const WITH = ['facility', 'donorProfile.donor', 'donorProfile.bloodType', 'mobileEvent', 'donation', 'bookedBy'];

    /**
     * The printed form of an appointment's id, e.g. APT-000123.
     */
    private const CODE_PATTERN = '/^APT-?(\d{1,10})$/i';

    /**
     * Filters: search (donor name, phone, email or ID, drive name, or an
     * APT- code), from and to (operational dates, inclusive), status.
     *
     * Newest day first, earliest slot first within a day.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $facilityId, array $filters): Builder
    {
        return DonationAppointment::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('appointment_datetime', '>=', OperationalDay::boundsFor($filters['from'])[0]))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('appointment_datetime', '<=', OperationalDay::boundsFor($filters['to'])[1]))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->applySearch($q, (string) $filters['search']))
            ->orderByRaw('DATE(appointment_datetime) DESC')
            ->orderBy('appointment_datetime')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DonationAppointment>
     */
    public function paginate(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($facilityId, $filters)->paginate($perPage);
    }

    /**
     * One of this centre's appointments, under a row lock.
     */
    public function lockForFacility(int $id, int $facilityId): ?DonationAppointment
    {
        return DonationAppointment::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    public function findForFacility(int $id, int $facilityId): ?DonationAppointment
    {
        return DonationAppointment::query()->with(self::WITH)->where('facility_id', $facilityId)->whereKey($id)->first();
    }

    /**
     * The centre's drives from today on, nearest first, with their registrations.
     *
     * @return Collection<int, MobileEvent>
     */
    public function upcomingDrives(int $facilityId): Collection
    {
        return MobileEvent::query()
            ->where('facility_id', $facilityId)
            ->whereDate('event_date', '>=', OperationalDay::todayAsDate())
            ->withCount(['appointments as registered' => fn ($query) => $query->active()])
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get();
    }

    private function applySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';
        $appointmentId = preg_match(self::CODE_PATTERN, $term, $m) === 1 ? (int) $m[1] : null;
        $donorId = preg_match('/^DONOR-(\d{1,10})$/i', $term, $d) === 1 ? (int) $d[1] : null;
        $phone = preg_replace('/[\s-]+/', '', $term) ?? $term;
        $localPhone = preg_match('/^0(9\d*)$/', $phone, $p) === 1 ? '%+63'.$p[1].'%' : null;

        return $query->where(function (Builder $inner) use ($like, $appointmentId, $donorId, $phone, $localPhone): void {
            $inner->whereHas('donorProfile.donor', fn (Builder $donor) => $donor
                ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                ->orWhere('phone', 'like', '%'.$phone.'%')
                ->when($localPhone !== null, fn (Builder $q) => $q->orWhere('phone', 'like', $localPhone))
                ->when($donorId !== null, fn (Builder $q) => $q->orWhere('users.id', $donorId)))
                ->orWhereHas('mobileEvent', fn (Builder $drive) => $drive->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->when($appointmentId !== null, fn (Builder $q) => $q->orWhere('donation_appointments.id', $appointmentId));
        });
    }
}
