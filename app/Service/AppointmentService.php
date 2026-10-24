<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\AppointmentScheduled;
use App\Repository\AppointmentRepository;
use App\Repository\EligibilityRepository;
use App\Support\AppointmentScreeningWindow;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AppointmentService
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly EligibilityRepository $eligibilityRepository,
        private readonly EligibilityRuleEvaluator $evaluator
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
                // So the booking calendar can grey out the days it is shut.
                'open_days' => $facility->open_days,
                'closed_dates' => array_values(array_filter(
                    $facility->closed_dates ?? [],
                    fn (array $c): bool => ($c['date'] ?? '') >= now()->toDateString()
                )),
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
                // Wall-clock 'HH:MM', so the donor's home can say a drive is on now.
                'start_time' => $event->start_time ? substr($event->start_time, 0, 5) : null,
                'end_time' => $event->end_time ? substr($event->end_time, 0, 5) : null,
                'registered' => (int) $event->registered,
                'total_slots' => $event->max_capacity,
                'status' => $event->status(),
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

        // A closed day, or a weekday the centre does not open, offers no times.
        if (! $facility->isOpenOn($day)) {
            return [];
        }

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

        // Resolved once for the donor rather than per booking.
        $latestScreenedAt = $this->eligibilityRepository->latestScreenedAt($profile->donor_id);

        return $this->appointmentRepository->forDonor($profile->donor_id)
            ->map(fn (DonationAppointment $a): array => $this->format($a, $latestScreenedAt))
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

        // No screening gate. A donor books first and answers the health
        // questionnaire the day before, so that what the blood centre reads at
        // the counter describes them as they are now rather than as they were
        // up to 90 days ago. See App\Support\AppointmentScreeningWindow.
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
                'status' => AppointmentStatus::Scheduled,
            ]);
        });

        $appointment->load(['facility', 'mobileEvent']);

        $this->sendConfirmation($user, $appointment);

        return $this->format($appointment);
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

        // The questionnaire was answered against the old date's window. If it
        // no longer falls inside the new one, the credential minted from it
        // must not survive the move -- otherwise a donor who shifts their
        // appointment a fortnight out walks in holding a working QR backed by
        // answers given for a visit that never happened.
        $screeningStillValid = $this->revokeCredentialIfWindowMoved($profile, $updated);

        return $this->format($updated->load(['facility', 'mobileEvent']))
            + ['requires_new_screening' => ! $screeningStillValid];
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

        $this->appointmentRepository->update($appointment, ['status' => AppointmentStatus::Cancelled]);

        // A check-in credential outlives no visit it was issued for.
        $this->eligibilityRepository->revokeQrTokens((int) $appointment->donor_id);

        return $this->format($appointment->load(['facility', 'mobileEvent']));
    }

    /**
     * Book an appointment for a donor who phoned the centre (project owner,
     * 2026-10-17), at the staff member's own centre or one of its drives.
     *
     * The portal's own rules, through the same guards, so a booking taken by
     * phone can never be one the donor could not have made: not in the past or
     * beyond the horizon, not before the donation interval has passed, not a
     * second active booking, and not into a full slot or drive. Two differences,
     * both because staff are not the donor: no email-verification gate (a
     * walk-in may have no address), and a donor with a standing deferral is
     * refused outright rather than left to the counter to turn away.
     *
     * A drive is a mobile blood drive this centre runs (MobileEvent). Its date is its own;
     * the time is one of the slots inside the drive's hours.
     *
     * @param  array{date?: string, time_slot: string, drive_id?: int|null, notes?: string|null}  $payload
     */
    public function bookOnBehalf(User $staff, Facility $facility, User $donor, array $payload, bool $deferred): DonationAppointment
    {
        $profile = $donor->donorProfile
            ?? throw ValidationException::withMessages(['donor_uuid' => ['This account has no donor profile.']]);

        if ($deferred) {
            throw new HttpResponseException(response()->json([
                'message' => 'This donor is deferred and cannot be booked to donate.',
                'code' => 'donor_deferred',
                'errors' => ['donor_uuid' => ['This donor is deferred and cannot be booked to donate.']],
            ], 422));
        }

        $drive = isset($payload['drive_id']) ? $this->ownUpcomingDrive($facility, (int) $payload['drive_id']) : null;

        if ($drive === null && $this->appointmentRepository->findBookableCenter($facility->id) === null) {
            throw ValidationException::withMessages([
                'date' => ['Your centre is not accepting donations at the moment.'],
            ]);
        }

        $times = array_column($this->slotsFor($facility, $drive?->event_date->toDateString() ?? $payload['date'], $drive), 'time');

        if (! in_array($payload['time_slot'], $times, true)) {
            throw ValidationException::withMessages([
                'time_slot' => ['Choose one of the listed time slots.'],
            ]);
        }

        $slot = $drive !== null
            ? $drive->event_date->copy()->setTimeFromTimeString($payload['time_slot'])
            : Carbon::parse($payload['date'].' '.$payload['time_slot']);

        $this->guardBookingWindow($slot);
        $this->guardDonationInterval($profile, $slot, forStaff: true);
        $this->guardNoActiveAppointment($profile->donor_id, forStaff: true);

        $appointment = DB::transaction(function () use ($profile, $facility, $drive, $slot, $payload, $staff): DonationAppointment {
            if ($drive !== null) {
                $this->guardDriveCapacity($drive->id);
            } else {
                $this->guardSlotAvailable($facility->id, $slot);
            }

            return $this->appointmentRepository->create([
                'donor_id' => $profile->donor_id,
                'facility_id' => $facility->id,
                'event_id' => $drive?->id,
                'appointment_datetime' => $slot,
                'status' => AppointmentStatus::Scheduled,
                'notes' => filled($payload['notes'] ?? null) ? trim((string) $payload['notes']) : null,
                'booked_by' => $staff->id,
            ]);
        });

        $appointment->load(['facility', 'mobileEvent']);

        // The same confirmation the portal sends: the donor learns of a booking
        // made for them, and gets its date and the questionnaire reminder.
        $this->sendConfirmation($donor, $appointment);

        return $appointment;
    }

    /**
     * Cancel a booking for the donor, as staff. Only a booking still waiting
     * for its donor: one under way is closed at the counter, and a missed one
     * is a no-show. The check-in credential goes with it, as in the portal.
     */
    public function cancelOnBehalf(DonationAppointment $appointment): DonationAppointment
    {
        if ($appointment->status !== AppointmentStatus::Scheduled) {
            throw new HttpResponseException(response()->json([
                'message' => 'Only a scheduled appointment can be cancelled.',
                'code' => 'appointment_not_active',
            ], 409));
        }

        $this->appointmentRepository->update($appointment, ['status' => AppointmentStatus::Cancelled]);
        $this->eligibilityRepository->revokeQrTokens((int) $appointment->donor_id);

        return $appointment;
    }

    /**
     * The times a staff member may book at their centre or one of its drives on
     * a date, with how many places each has left.
     *
     * At the centre, the portal's own slot grid and per-slot capacity. At a
     * drive, the slots inside the drive's hours (the centre's grid when it set
     * none), all sharing the drive's one capacity; null when it has no limit.
     *
     * @return array<int, array{time: string, end: string, available: int|null, total: int|null}>
     */
    public function slotsFor(Facility $facility, string $date, ?MobileEvent $drive = null): array
    {
        $interval = max(5, (int) $facility->slot_interval_minutes);
        $end = fn (string $time): string => Carbon::parse($time)->addMinutes($interval)->format('H:i');

        if ($drive === null) {
            if ($this->appointmentRepository->findBookableCenter($facility->id) === null) {
                return [];
            }

            return array_map(fn (array $slot): array => [...$slot, 'end' => $end($slot['time'])], $this->timeSlots($facility->id, $date));
        }

        $times = $this->slotTimes($facility);

        if (filled($drive->start_time) && filled($drive->end_time)) {
            $times = [];
            $from = Carbon::parse($drive->start_time);
            $until = Carbon::parse($drive->end_time)->subMinutes($interval);

            while ($from->lessThanOrEqualTo($until)) {
                $times[] = $from->format('H:i');
                $from->addMinutes($interval);
            }
        }

        $left = $drive->max_capacity === null
            ? null
            : max(0, $drive->max_capacity - (int) ($drive->registered ?? $drive->appointments()->active()->count()));

        return array_map(function (string $time) use ($drive, $left, $end): array {
            $isPast = $drive->event_date->copy()->setTimeFromTimeString($time)->isPast();

            return [
                'time' => $time,
                'end' => $end($time),
                'available' => $isPast ? 0 : $left,
                'total' => $drive->max_capacity,
            ];
        }, $times);
    }

    /**
     * One of this centre's own upcoming drives, or a field error.
     */
    public function ownUpcomingDrive(Facility $facility, int $driveId): MobileEvent
    {
        $drive = $this->appointmentRepository->findUpcomingDrive($driveId);

        if ($drive === null || (int) $drive->facility_id !== (int) $facility->id) {
            throw ValidationException::withMessages([
                'drive_id' => ['Choose one of your centre\'s upcoming drives.'],
            ]);
        }

        return $drive;
    }

    /**
     * Revoke the donor's QR when a moved appointment leaves its questionnaire
     * outside the new screening window.
     *
     * Returns whether the standing questionnaire still covers the new date.
     */
    private function revokeCredentialIfWindowMoved(
        DonorProfile $profile,
        DonationAppointment $appointment
    ): bool {
        $screening = $this->eligibilityRepository->currentValidScreening($profile->donor_id);

        if ($screening !== null && AppointmentScreeningWindow::contains(
            $appointment,
            CarbonImmutable::parse($screening->screened_at)
        )) {
            return true;
        }

        $this->eligibilityRepository->revokeQrTokens($profile->donor_id);

        return false;
    }

    /**
     * Email the donor their appointment details, and file the in-app copy.
     *
     * After the commit, and never allowed to fail the booking: the slot is
     * already held, so a mailer error here is a message to chase up rather
     * than a reason to tell the donor their appointment did not go through.
     */
    private function sendConfirmation(User $user, DonationAppointment $appointment): void
    {
        try {
            $user->notify(new AppointmentScheduled($appointment));
        } catch (Throwable $exception) {
            Log::warning('Could not send the appointment confirmation.', [
                'donor_id' => $user->id,
                'appointment_id' => $appointment->id,
                'exception' => $exception->getMessage(),
            ]);
        }
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
    private function guardDonationInterval(DonorProfile $profile, Carbon $slot, bool $forStaff = false): void
    {
        $lastDonationAt = $this->eligibilityRepository->lastBloodDrawnAt($profile->donor_id);
        $nextEligible = $this->evaluator->nextEligibleDate($lastDonationAt);

        if ($nextEligible === null || $slot->greaterThanOrEqualTo($nextEligible)) {
            return;
        }

        $message = ($forStaff ? 'This donor cannot donate again until ' : 'You cannot donate again until ')
            .$nextEligible->toDateString().'.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => 'below_min_interval',
            'errors' => [
                'date' => [$message],
            ],
            'next_eligible_date' => $nextEligible->toDateString(),
        ], 422));
    }

    /**
     * Reject a second concurrent booking for the same donor.
     */
    private function guardNoActiveAppointment(int $donorId, bool $forStaff = false): void
    {
        if (! $this->appointmentRepository->hasActiveAppointment($donorId)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $forStaff
                ? 'This donor already has an upcoming appointment. Cancel or move that one first.'
                : 'You already have an upcoming appointment. Cancel or reschedule it first.',
            'code' => 'duplicate_appointment',
        ], 409));
    }

    /**
     * Reject a booking once the slot is full, re-checked under a row lock.
     */
    private function guardSlotAvailable(int $facilityId, Carbon $slot, ?int $excludingId = null): void
    {
        $facility = $this->appointmentRepository->findBookableCenter($facilityId);

        if ($facility !== null && ($reason = $facility->closedReasonOn($slot)) !== null) {
            throw new HttpResponseException(response()->json([
                'message' => 'The centre takes no bookings on '.$slot->format('l, j F Y').": {$reason}. Please choose another day.",
                'code' => 'centre_closed',
            ], 422));
        }

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
        if ($appointment->status->holdsSlot()) {
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
            'message' => $windowHours > 0
                ? "Appointments can only be changed more than {$windowHours} hours in advance."
                : 'This appointment has already started and can no longer be changed.',
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

    /**
     * @return array<string, mixed>
     */
    private function format(DonationAppointment $appointment, ?Carbon $latestScreenedAt = null): array
    {
        return [
            'id' => $appointment->id,
            'appointment_datetime' => $appointment->appointment_datetime->toIso8601String(),
            'date' => $appointment->appointment_datetime->toDateString(),
            'time' => $appointment->appointment_datetime->format('H:i'),
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'appointment_type' => $appointment->event_id ? 'mobile' : 'walk_in',
            'facility_name' => $appointment->facility?->name,
            'drive_name' => $appointment->mobileEvent?->name,
            'can_cancel' => $appointment->status->holdsSlot()
                && $appointment->appointment_datetime->greaterThan(
                    now()->addHours((int) config('donation.cancellation_window_hours'))
                ),

            // Where the health questionnaire stands for this booking. Booking no
            // longer requires one, so this is what tells the donor when theirs
            // falls due -- and the only thing on the appointments screen that
            // does.
            'screening_window_opens_on' => AppointmentScreeningWindow::opensOn($appointment),
            'screening_status' => $this->screeningStatusFor($appointment, $latestScreenedAt),
        ];
    }

    /**
     * How the questionnaire stands against one booking.
     *
     * `null` for a booking that no longer holds its slot: a cancelled or
     * completed appointment has nothing outstanding, and reporting it as
     * "missed" would be both wrong and alarming.
     */
    private function screeningStatusFor(DonationAppointment $appointment, ?Carbon $latestScreenedAt): ?string
    {
        if (! $appointment->status->holdsSlot()) {
            return null;
        }

        if ($latestScreenedAt !== null && AppointmentScreeningWindow::contains(
            $appointment,
            CarbonImmutable::parse($latestScreenedAt)
        )) {
            return 'answered';
        }

        if (AppointmentScreeningWindow::isTooEarly($appointment)) {
            return 'not_due';
        }

        // The window has opened and closed with nothing answered against it.
        // The donor can still fill it in at the counter, so this is a prompt
        // rather than a refusal.
        if (OperationalDay::today()->greaterThan(AppointmentScreeningWindow::closesAt($appointment))) {
            return 'missed';
        }

        return 'due';
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
