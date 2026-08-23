<?php

namespace App\Service;

use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Repository\AppointmentRepository;
use App\Repository\EligibilityRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly EligibilityRepository $eligibilityRepository,
        private readonly EligibilityRuleEvaluator $evaluator,
        private readonly EligibilityService $eligibilityService
    ) {}

    /**
     * List blood centres a donor can book against.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bloodCenters(): array
    {
        return $this->appointmentRepository->bookableCenters()
            ->map(fn (Facility $facility): array => [
                'id' => $facility->id,
                'name' => $facility->name,
                'location' => $facility->address,
                'hours' => $facility->operating_hours,
                'status' => $facility->is_accepting_donations ? 'Open today' : 'Closed',
            ])
            ->all();
    }

    /**
     * List upcoming mobile blood drives with their remaining capacity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bloodDrives(): array
    {
        return $this->appointmentRepository->upcomingDrives()
            ->map(fn (MobileEvent $event): array => [
                'id' => $event->id,
                'name' => $event->name,
                'location' => $event->location,
                'date' => $event->event_date->toDateString(),
                'registered' => (int) $event->registered,
                'total_slots' => $event->max_capacity,
                'status' => $this->driveStatus($event),
            ])
            ->all();
    }

    /**
     * Build the bookable time slots for a centre on a given date.
     *
     * Slots are derived from the facility's operating window and per-slot
     * capacity, minus the appointments already booked.
     *
     * @return array<int, array{time: string, available: int, total: int}>
     */
    public function timeSlots(int $centerId, string $date): array
    {
        $facility = $this->appointmentRepository->findBookableCenter($centerId);

        if (! $facility) {
            throw ValidationException::withMessages([
                'center_id' => ['This blood centre is not currently accepting donations.'],
            ]);
        }

        $day = Carbon::parse($date);
        $booked = $this->appointmentRepository->bookedCountsByTime($facility->id, $day);
        $capacity = max(1, (int) $facility->slot_capacity);

        return collect($this->slotTimes($facility))
            ->map(function (string $time) use ($booked, $capacity, $day): array {
                $isPast = Carbon::parse($day->toDateString().' '.$time)->isPast();

                return [
                    'time' => $time,
                    'available' => $isPast ? 0 : max(0, $capacity - (int) $booked->get($time, 0)),
                    'total' => $capacity,
                ];
            })
            ->all();
    }

    /**
     * List the authenticated donor's own appointments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForDonor(User $user): array
    {
        $profile = $this->requireDonorProfile($user);

        return $this->appointmentRepository->forDonor($profile->donor_id)
            ->map(fn (DonationAppointment $appointment): array => $this->format($appointment))
            ->all();
    }

    /**
     * Book an appointment, enforcing every server-side gate.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function book(User $user, array $payload): array
    {
        $profile = $this->requireDonorProfile($user);

        $this->guardEmailVerified($user);
        $this->guardValidScreening($profile);

        $slot = $this->resolveSlot($payload);
        $this->guardBookingWindow($slot);
        $this->guardDonationInterval($profile, $slot);
        $this->guardNoActiveAppointment($profile->donor_id);

        $appointment = DB::transaction(function () use ($profile, $payload, $slot): DonationAppointment {
            $facilityId = $this->resolveFacilityId($payload);
            $eventId = $payload['drive_id'] ?? null;

            if ($eventId !== null) {
                $this->guardDriveCapacity((int) $eventId);
            } else {
                $this->guardSlotAvailable($facilityId, $slot);
            }

            return $this->appointmentRepository->create([
                'donor_id' => $profile->donor_id,
                'facility_id' => $facilityId,
                'event_id' => $eventId,
                'appointment_datetime' => $slot,
                'status' => 'scheduled',
            ]);
        });

        return $this->format($appointment->load(['facility', 'mobileEvent']));
    }

    /**
     * Move an existing appointment to a new slot.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reschedule(User $user, DonationAppointment $appointment, array $payload): array
    {
        $profile = $this->requireDonorProfile($user);

        $this->guardEmailVerified($user);
        $this->guardValidScreening($profile);
        $this->guardMutable($appointment);
        $this->guardChangeWindow($appointment);

        $slot = $this->resolveSlot($payload);
        $this->guardBookingWindow($slot);
        $this->guardDonationInterval($profile, $slot);

        $updated = DB::transaction(function () use ($appointment, $payload, $slot): DonationAppointment {
            $facilityId = $this->resolveFacilityId($payload);
            $eventId = $payload['drive_id'] ?? null;

            if ($eventId !== null) {
                $this->guardDriveCapacity((int) $eventId);
            } else {
                $this->guardSlotAvailable($facilityId, $slot, $appointment->id);
            }

            return $this->appointmentRepository->update($appointment, [
                'facility_id' => $facilityId,
                'event_id' => $eventId,
                'appointment_datetime' => $slot,
            ]);
        });

        return $this->format($updated->load(['facility', 'mobileEvent']));
    }

    /**
     * Cancel an appointment without deleting the record.
     *
     * @return array<string, mixed>
     */
    public function cancel(DonationAppointment $appointment): array
    {
        $this->guardMutable($appointment);
        $this->guardChangeWindow($appointment);

        $this->appointmentRepository->update($appointment, ['status' => 'cancelled']);

        return $this->format($appointment->load(['facility', 'mobileEvent']));
    }

    /**
     * Reject booking for a donor who has not confirmed their email address.
     */
    private function guardEmailVerified(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Please verify your email address before booking an appointment.',
            'code' => 'email_unverified',
        ], 403));
    }

    /**
     * Reject booking without a passed, unexpired preliminary screening.
     */
    private function guardValidScreening(DonorProfile $profile): void
    {
        if ($this->eligibilityRepository->currentValidScreening($profile->donor_id)) {
            return;
        }

        $latest = $this->eligibilityRepository->latestScreening($profile->donor_id);
        $status = $this->eligibilityService->resolveStatus($latest);

        [$code, $message] = match ($status->value) {
            'expired' => ['screening_expired', 'Your eligibility screening has expired. Please complete a new screening.'],
            'deferred' => ['screening_required', 'You were deferred at your last screening. Please complete a new screening.'],
            default => ['screening_required', 'Please complete an eligibility screening before booking an appointment.'],
        };

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], 403));
    }

    /**
     * Reject slots in the past or beyond the bookable horizon.
     */
    private function guardBookingWindow(Carbon $slot): void
    {
        if ($slot->isPast()) {
            throw ValidationException::withMessages([
                'date' => ['Appointments cannot be booked in the past.'],
            ]);
        }

        $horizon = now()->addDays((int) config('donation.booking_horizon_days'));

        if ($slot->greaterThan($horizon)) {
            throw ValidationException::withMessages([
                'date' => ['Appointments can only be booked up to '.config('donation.booking_horizon_days').' days ahead.'],
            ]);
        }
    }

    /**
     * Reject a slot that falls before the donor's next eligible donation date.
     */
    private function guardDonationInterval(DonorProfile $profile, Carbon $slot): void
    {
        $lastDonationAt = $this->eligibilityRepository->lastCompletedDonationAt($profile->donor_id);
        $nextEligible = $this->evaluator->nextEligibleDate($lastDonationAt);

        if ($nextEligible === null || $slot->greaterThanOrEqualTo($nextEligible)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'You cannot donate again until '.$nextEligible->toDateString().'.',
            'code' => 'below_min_interval',
            'errors' => [
                'date' => ['You cannot donate again until '.$nextEligible->toDateString().'.'],
            ],
            'next_eligible_date' => $nextEligible->toDateString(),
        ], 422));
    }

    /**
     * Reject a second concurrent booking for the same donor.
     */
    private function guardNoActiveAppointment(int $donorId): void
    {
        if (! $this->appointmentRepository->hasActiveAppointment($donorId)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'You already have an upcoming appointment. Cancel or reschedule it first.',
            'code' => 'duplicate_appointment',
        ], 409));
    }

    /**
     * Reject a booking once the slot is full, re-checked under a row lock.
     */
    private function guardSlotAvailable(int $facilityId, Carbon $slot, ?int $excludingId = null): void
    {
        $facility = $this->appointmentRepository->findBookableCenter($facilityId);
        $capacity = max(1, (int) ($facility?->slot_capacity ?? 1));
        $booked = $this->appointmentRepository->lockedSlotCount($facilityId, $slot);

        if ($excludingId !== null) {
            $booked = max(0, $booked - 1);
        }

        if ($booked < $capacity) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'That time slot has just been taken. Please choose another.',
            'code' => 'slot_unavailable',
        ], 409));
    }

    /**
     * Reject a registration once a drive has reached capacity.
     */
    private function guardDriveCapacity(int $eventId): void
    {
        $drive = $this->appointmentRepository->findUpcomingDrive($eventId);

        if (! $drive) {
            throw ValidationException::withMessages([
                'drive_id' => ['This blood drive is no longer available.'],
            ]);
        }

        if ($drive->max_capacity === null) {
            return;
        }

        if ($this->appointmentRepository->lockedDriveCount($eventId) < $drive->max_capacity) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This blood drive is fully booked.',
            'code' => 'drive_full',
        ], 409));
    }

    /**
     * Reject changes to an appointment that is no longer active.
     */
    private function guardMutable(DonationAppointment $appointment): void
    {
        if (in_array($appointment->status, DonationAppointment::ACTIVE_STATUSES, true)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This appointment can no longer be changed.',
            'code' => 'appointment_not_active',
        ], 409));
    }

    /**
     * Reject changes made inside the cancellation window.
     */
    private function guardChangeWindow(DonationAppointment $appointment): void
    {
        $windowHours = (int) config('donation.cancellation_window_hours');

        if ($appointment->appointment_datetime->greaterThan(now()->addHours($windowHours))) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => "Appointments can only be changed more than {$windowHours} hours in advance.",
            'code' => 'cancellation_window_passed',
        ], 422));
    }

    /**
     * Resolve the requested date and time into a single instant.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveSlot(array $payload): Carbon
    {
        if (($payload['type'] ?? null) === 'mobile') {
            $drive = $this->appointmentRepository->findUpcomingDrive((int) $payload['drive_id']);

            if (! $drive) {
                throw ValidationException::withMessages([
                    'drive_id' => ['This blood drive is no longer available.'],
                ]);
            }

            return $drive->event_date->copy()->setTimeFromTimeString($payload['time_slot'] ?? '09:00');
        }

        return Carbon::parse($payload['date'].' '.$payload['time_slot']);
    }

    /**
     * Resolve which facility the appointment belongs to.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveFacilityId(array $payload): int
    {
        if (($payload['type'] ?? null) === 'mobile') {
            $drive = $this->appointmentRepository->findUpcomingDrive((int) $payload['drive_id']);

            return (int) $drive->facility_id;
        }

        $facility = $this->appointmentRepository->findBookableCenter((int) $payload['center_id']);

        if (! $facility) {
            throw ValidationException::withMessages([
                'center_id' => ['This blood centre is not currently accepting donations.'],
            ]);
        }

        return $facility->id;
    }

    /**
     * Build the list of slot start times a facility offers.
     *
     * @return array<int, string>
     */
    private function slotTimes(Facility $facility): array
    {
        $start = Carbon::parse($facility->slots_start_at ?? '08:00');
        $end = Carbon::parse($facility->slots_end_at ?? '15:00');
        $interval = max(5, (int) $facility->slot_interval_minutes);

        $times = [];

        while ($start->lessThanOrEqualTo($end)) {
            $times[] = $start->format('H:i');
            $start->addMinutes($interval);
        }

        return $times;
    }

    private function driveStatus(MobileEvent $event): string
    {
        if ($event->max_capacity !== null && (int) $event->registered >= $event->max_capacity) {
            return 'Full';
        }

        return $event->event_date->isToday() ? 'Open' : 'Upcoming';
    }

    /**
     * @return array<string, mixed>
     */
    private function format(DonationAppointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'appointment_datetime' => $appointment->appointment_datetime->toIso8601String(),
            'date' => $appointment->appointment_datetime->toDateString(),
            'time' => $appointment->appointment_datetime->format('H:i'),
            'status' => $appointment->status,
            'appointment_type' => $appointment->event_id ? 'mobile' : 'walk_in',
            'facility_name' => $appointment->facility?->name,
            'drive_name' => $appointment->mobileEvent?->name,
            'can_cancel' => in_array($appointment->status, DonationAppointment::ACTIVE_STATUSES, true)
                && $appointment->appointment_datetime->greaterThan(
                    now()->addHours((int) config('donation.cancellation_window_hours'))
                ),
        ];
    }

    private function requireDonorProfile(User $user): DonorProfile
    {
        $profile = $user->donorProfile()->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        return $profile;
    }
}
