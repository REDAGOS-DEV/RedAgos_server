<?php

namespace App\Service;

use App\Models\Facility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * When a blood centre takes bookings: its open weekdays, the hours of its
 * first and last slot, how long a slot is and how many donors each takes,
 * whether it takes bookings at all, and the dates it is closed.
 *
 * The Donor Portal and the counter build their slots from this
 * (AppointmentService); the centre's Admin and its Medical Receptionist keep
 * it (booking.configure).
 */
class BookingScheduleService
{
    /** Slot lengths offered, in minutes. */
    public const INTERVALS = [10, 15, 20, 30, 45, 60];

    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(User $staff): array
    {
        return $this->present($this->facility($staff), $staff);
    }

    /**
     * @param  array<string, mixed>  $payload  validated (UpdateBookingScheduleRequest)
     * @return array<string, mixed>
     */
    public function update(User $staff, array $payload): array
    {
        $facility = $this->facility($staff);

        $openDays = array_values(array_unique(array_map('intval', $payload['open_days'])));
        sort($openDays);

        $closed = collect($payload['closed_dates'] ?? [])
            ->map(fn (array $c): array => ['date' => $c['date'], 'reason' => filled($c['reason'] ?? null) ? trim((string) $c['reason']) : null])
            ->unique('date')
            ->sortBy('date')
            ->values()
            ->all();

        $before = $this->present($facility, $staff);

        $facility->forceFill([
            'open_days' => $openDays,
            'slots_start_at' => $payload['slots_start_at'],
            'slots_end_at' => $payload['slots_end_at'],
            'slot_interval_minutes' => (int) $payload['slot_interval_minutes'],
            'slot_capacity' => (int) $payload['slot_capacity'],
            'is_accepting_donations' => (bool) $payload['is_accepting_donations'],
            'closed_dates' => $closed,
        ])->save();

        $after = $this->present($facility->fresh(), $staff);

        $this->auditLogger->record($staff, 'facility.booking_schedule_updated', $facility, [
            'facility_id' => $facility->id,
            'before' => array_intersect_key($before, array_flip(['open_days', 'slots_start_at', 'slots_end_at', 'slot_interval_minutes', 'slot_capacity', 'is_accepting_donations'])),
            'after' => array_intersect_key($after, array_flip(['open_days', 'slots_start_at', 'slots_end_at', 'slot_interval_minutes', 'slot_capacity', 'is_accepting_donations'])),
            'closed_dates' => count($closed),
        ]);

        return ['message' => 'Booking schedule saved. The Donor Portal shows it from now on.', 'data' => $after];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Facility $facility, User $staff): array
    {
        $start = substr((string) ($facility->slots_start_at ?? '08:00'), 0, 5);
        $end = substr((string) ($facility->slots_end_at ?? '15:00'), 0, 5);
        $interval = max(5, (int) ($facility->slot_interval_minutes ?: 30));
        $capacity = max(1, (int) ($facility->slot_capacity ?: 1));
        $openDays = is_array($facility->open_days) && $facility->open_days !== [] ? array_map('intval', $facility->open_days) : range(0, 6);

        // The last slot starts at the end time (AppointmentService::slotTimes).
        $slots = intdiv(max(0, $this->minutes($end) - $this->minutes($start)), $interval) + 1;
        $today = CarbonImmutable::now((string) config('blood_center.timezone', 'Asia/Manila'))->toDateString();

        return [
            'open_days' => $openDays,
            'slots_start_at' => $start,
            'slots_end_at' => $end,
            'slot_interval_minutes' => $interval,
            'slot_capacity' => $capacity,
            'is_accepting_donations' => (bool) $facility->is_accepting_donations,
            'closed_dates' => array_values(array_filter($facility->closed_dates ?? [], fn (array $c): bool => ($c['date'] ?? '') >= $today)),
            'operating_hours' => $facility->operating_hours,
            'slots_per_day' => $slots,
            'donors_per_day' => $slots * $capacity,
            'intervals' => self::INTERVALS,
            'can_edit' => $staff->can('booking.configure'),
        ];
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function facility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));
    }
}
