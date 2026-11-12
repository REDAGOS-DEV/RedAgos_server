<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\DriveCancelled;
use App\Notifications\DriveRescheduled;
use App\Repository\EligibilityRepository;
use App\Repository\MobileEventRepository;
use App\Support\AppointmentScreeningWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The blood centre's own view of its mobile drives.
 *
 * Donors read drives through BookingCatalogController, which is deliberately
 * read-only. Scheduling one is an act of the Collection department, so it lives
 * here behind drives.manage.
 *
 * A drive's lifecycle (drive pre-registration, phase 1): a draft is seen by
 * staff alone; publishing shows it to donors and tells the centre's donors;
 * registration can be closed and reopened while it is published; cancelling
 * cancels its waiting bookings and tells those donors why. Every change takes
 * the drive's row lock, the one bookings take, so none interleaves with a
 * booking.
 */
class MobileEventService
{
    /** The fields an edit may change, as the form sends them. */
    private const EDITABLE = [
        'name',
        'location',
        'event_date',
        'start_time',
        'end_time',
        'assigned_staff',
        'announcement',
    ];

    public function __construct(
        private readonly MobileEventRepository $mobileEventRepository,
        private readonly BloodDriveAnnouncer $bloodDriveAnnouncer,
        private readonly EligibilityRepository $eligibilityRepository,
        private readonly AuditLogger $auditLogger,
        private readonly DrivePreRegistrationService $preRegistrations
    ) {}

    /**
     * The drives screen: headline figures plus this facility's drives.
     *
     * @return array<string, mixed>
     */
    public function list(User $staff): array
    {
        $facility = $this->requireFacility($staff);

        $drives = $this->mobileEventRepository->forFacility($facility->id);
        $upcoming = $drives->filter(
            fn (MobileEvent $event): bool => ! $event->isCancelled() && ! $event->event_date->isBefore(Carbon::today())
        );

        return [
            'upcoming_drives_count' => $upcoming->count(),
            // The pre-registered donors coming to the upcoming drives.
            'total_pre_registered' => (int) $upcoming->sum(fn (MobileEvent $event): int => $event->preRegisteredCount()),
            // Drives only: a drive booking or a paper sheet, with a bag drawn.
            'units_collected_month' => $this->mobileEventRepository->unitsCollectedAtDrivesInMonth(
                $facility->id,
                Carbon::today()
            ),
            // The same, per month ("YYYY-MM" => bags), for the month strip.
            'units_collected_by_month' => $this->mobileEventRepository->unitsCollectedAtDrivesByMonth($facility->id),
            'drives' => $drives->map(fn (MobileEvent $event): array => $this->format($event))->all(),
        ];
    }

    /**
     * Schedule a drive for the facility the caller acts for.
     *
     * Published at once unless the form asks for a draft, as every drive was
     * before drafts existed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $publish = (bool) ($payload['publish'] ?? true);

        $event = $this->mobileEventRepository->create([
            ...array_intersect_key($payload, array_flip(self::EDITABLE)),
            // Both come from the token, never from the payload.
            'facility_id' => $facility->id,
            'created_by' => $staff->id,
            'published_at' => $publish ? now() : null,
        ]);

        $this->auditLogger->record($staff, 'drive.created', $event, [
            'facility_id' => $facility->id,
            'published' => $publish,
        ]);

        // The centre's donors hear about it, each on the channels they chose.
        // A draft is announced when it is published.
        if ($publish) {
            $this->bloodDriveAnnouncer->announce($event);
        }

        return $this->format($event);
    }

    /**
     * Change a drive's details.
     *
     * A booked drive can move (project owner, 2026-11-12: "should a booked
     * drive be movable with the donors notified - yes"). Its waiting bookings
     * move with it and the booked donors and the people on its list are told.
     * The date cannot move while anyone is checked in. A drive has no booking
     * limit.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $staff, int $driveId, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        [$changed, $moved, $rescheduled] = DB::transaction(function () use ($facility, $driveId, $payload): array {
            $drive = $this->lockChangeable($facility, $driveId);

            $attributes = array_intersect_key($payload, array_flip(self::EDITABLE));

            // '08:00' from the form is the '08:00:00' PostgreSQL stored, not a change.
            foreach (['start_time', 'end_time'] as $time) {
                if (array_key_exists($time, $attributes) && $this->minutes($attributes[$time]) === $this->minutes($drive->getOriginal($time))) {
                    unset($attributes[$time]);
                }
            }

            $drive->fill($attributes);

            $rescheduled = $this->scheduleChanged($drive);
            $dateMoved = $drive->isDirty('event_date')
                && $drive->event_date->toDateString() !== Carbon::parse($drive->getOriginal('event_date'))->toDateString();

            if ($dateMoved) {
                $checkedIn = $this->mobileEventRepository->activeCountsByStatus($drive->id)[AppointmentStatus::Confirmed->value] ?? 0;

                if ($checkedIn > 0) {
                    throw $this->refuseField(
                        'event_date',
                        'drive_in_progress',
                        $this->donors($checkedIn).' checked in at this drive. Finish or close their visits at the counter before moving it.'
                    );
                }
            }

            $changed = array_keys($drive->getDirty());
            $drive->save();

            // The bookings waiting for their donors move with the drive.
            $moved = $rescheduled ? $this->moveBookings($drive) : collect();

            return [$changed, $moved, $rescheduled];
        });

        $drive = $this->mobileEventRepository->fresh($driveId);

        // Everyone who said they were coming hears the new date and hours.
        $donorsNotified = $rescheduled ? $this->notifyRescheduled($drive, $moved) : 0;
        $registrantsNotified = $rescheduled ? $this->preRegistrations->notifyDriveRescheduled($drive) : 0;

        if ($changed !== []) {
            $this->auditLogger->record($staff, 'drive.updated', $drive, [
                'facility_id' => $facility->id,
                'fields' => $changed,
                ...($rescheduled ? [
                    'appointments_moved' => $moved->count(),
                    'donors_notified' => $donorsNotified,
                    'registrants_notified' => $registrantsNotified,
                ] : []),
            ]);
        }

        return [
            ...$this->format($drive),
            'appointments_moved' => $moved->count(),
        ];
    }

    /**
     * Show a draft to donors and tell the centre's donors about it.
     *
     * @return array<string, mixed>
     */
    public function publish(User $staff, int $driveId): array
    {
        $facility = $this->requireFacility($staff);

        $published = DB::transaction(function () use ($facility, $driveId): bool {
            $drive = $this->lockChangeable($facility, $driveId);

            if ($drive->isPublished()) {
                return false;
            }

            $drive->published_at = now();
            $drive->save();

            return true;
        });

        $drive = $this->mobileEventRepository->fresh($driveId);

        if ($published) {
            $this->auditLogger->record($staff, 'drive.published', $drive, ['facility_id' => $facility->id]);
            $this->bloodDriveAnnouncer->announce($drive);
        }

        return $this->format($drive);
    }

    /**
     * Stop or resume new bookings for a published drive. The bookings already
     * held stand either way.
     *
     * @return array<string, mixed>
     */
    public function setRegistrationOpen(User $staff, int $driveId, bool $open): array
    {
        $facility = $this->requireFacility($staff);

        $moved = DB::transaction(function () use ($facility, $driveId, $open): bool {
            $drive = $this->lockChangeable($facility, $driveId);

            if (! $drive->isPublished()) {
                throw $this->refuse(409, 'drive_not_published', 'Publish this drive first. Donors cannot register for a draft.');
            }

            if (($drive->registration_closed_at === null) === $open) {
                return false;
            }

            $drive->registration_closed_at = $open ? null : now();
            $drive->save();

            return true;
        });

        $drive = $this->mobileEventRepository->fresh($driveId);

        if ($moved) {
            $this->auditLogger->record(
                $staff,
                $open ? 'drive.registration_reopened' : 'drive.registration_closed',
                $drive,
                ['facility_id' => $facility->id]
            );
        }

        return $this->format($drive);
    }

    /**
     * Cancel a drive that has not been held, with every booking still waiting
     * for its donor, and tell those donors why.
     *
     * Refused while any donor is checked in: their visit is under way, and is
     * closed at the counter first. Each cancelled booking loses its check-in
     * QR, as a booking the donor cancels does.
     *
     * @return array<string, mixed>
     */
    public function cancel(User $staff, int $driveId, string $reason): array
    {
        $facility = $this->requireFacility($staff);

        $affected = DB::transaction(function () use ($facility, $driveId, $reason, $staff): Collection {
            $drive = $this->lockChangeable($facility, $driveId);

            $checkedIn = $this->mobileEventRepository->activeCountsByStatus($drive->id)[AppointmentStatus::Confirmed->value] ?? 0;

            if ($checkedIn > 0) {
                throw $this->refuse(
                    409,
                    'drive_in_progress',
                    $this->donors($checkedIn).' checked in at this drive. Finish or close their visits at the counter before cancelling it.'
                );
            }

            $appointments = $this->mobileEventRepository->scheduledAppointments($drive->id);

            foreach ($appointments as $appointment) {
                $appointment->status = AppointmentStatus::Cancelled;
                $appointment->save();
                $this->eligibilityRepository->revokeQrTokens((int) $appointment->donor_id);
            }

            $drive->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $staff->id,
                'cancellation_reason' => trim($reason),
            ])->save();

            return $appointments;
        });

        $drive = $this->mobileEventRepository->fresh($driveId);
        $notified = $this->notifyCancelled($drive, $affected);
        // And the people on its list, who may hold no account.
        $registrantsNotified = $this->preRegistrations->notifyDriveCancelled($drive);

        $this->auditLogger->record($staff, 'drive.cancelled', $drive, [
            'facility_id' => $facility->id,
            'appointments_cancelled' => $affected->count(),
            'donors_notified' => $notified,
            'registrants_notified' => $registrantsNotified,
        ]);

        return [
            ...$this->format($drive),
            'appointments_cancelled' => $affected->count(),
        ];
    }

    /**
     * Move each booking still waiting for its donor to the drive's new day.
     * It keeps its time when that falls inside the new hours, and takes the
     * new start otherwise. A donor whose questionnaire no longer covers the
     * new day loses the QR minted from it, as when a donor moves their own
     * booking (AppointmentService::reschedule()).
     *
     * @return Collection<int, DonationAppointment> the bookings moved, with their donors
     */
    private function moveBookings(MobileEvent $drive): Collection
    {
        $start = $this->minutes($drive->start_time);
        $end = $this->minutes($drive->end_time);

        return $this->mobileEventRepository->scheduledAppointments($drive->id)
            ->each(function (DonationAppointment $appointment) use ($drive, $start, $end): void {
                $time = $appointment->appointment_datetime->format('H:i');

                if ($start !== null && ($time < $start || ($end !== null && $time >= $end))) {
                    $time = $start;
                }

                $appointment->appointment_datetime = $drive->event_date->copy()->setTimeFromTimeString($time);
                $appointment->save();

                $screening = $this->eligibilityRepository->currentValidScreening((int) $appointment->donor_id);

                if ($screening === null || ! AppointmentScreeningWindow::contains($appointment, CarbonImmutable::parse($screening->screened_at))) {
                    $this->eligibilityRepository->revokeQrTokens((int) $appointment->donor_id);
                }
            });
    }

    /**
     * Tell each donor whose booking moved with the drive. After the commit,
     * and never allowed to undo it.
     *
     * @param  Collection<int, DonationAppointment>  $appointments
     */
    private function notifyRescheduled(MobileEvent $drive, Collection $appointments): int
    {
        $sent = 0;

        foreach ($appointments as $appointment) {
            $donor = $appointment->donorProfile?->donor;

            if ($donor === null) {
                continue;
            }

            try {
                $donor->notify(new DriveRescheduled($drive, $appointment));
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('Could not tell a donor their drive moved.', [
                    'mobile_event_id' => $drive->id,
                    'appointment_id' => $appointment->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Tell each donor whose booking went with the drive. After the commit, and
     * never allowed to undo it: a mailer error is logged and chased up.
     *
     * @param  Collection<int, DonationAppointment>  $appointments
     */
    private function notifyCancelled(MobileEvent $drive, Collection $appointments): int
    {
        $donors = $appointments
            ->map(fn (DonationAppointment $appointment): ?User => $appointment->donorProfile?->donor)
            ->filter()
            ->unique('id')
            ->values();

        if ($donors->isEmpty()) {
            return 0;
        }

        try {
            Notification::send($donors, new DriveCancelled($drive));
        } catch (Throwable $exception) {
            Log::warning('Could not send the drive cancellation notices.', [
                'mobile_event_id' => $drive->id,
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }

        return $donors->count();
    }

    /**
     * Lock one of the facility's drives that can still change: neither
     * cancelled nor already held.
     */
    private function lockChangeable(Facility $facility, int $driveId): MobileEvent
    {
        $drive = $this->mobileEventRepository->lockForFacility($driveId, $facility->id)
            ?? throw $this->refuse(404, 'drive_not_found', 'That drive was not found at your facility.');

        if ($drive->isCancelled()) {
            throw $this->refuse(409, 'drive_cancelled', 'This drive was cancelled and can no longer be changed.');
        }

        if ($drive->hasPassed()) {
            throw $this->refuse(409, 'drive_completed', 'This drive has already been held and can no longer be changed.');
        }

        return $drive;
    }

    /**
     * Whether the edit moves the drive's day or hours. Times are compared as
     * 'HH:MM', since PostgreSQL hands TIME back with seconds.
     */
    private function scheduleChanged(MobileEvent $drive): bool
    {
        return $drive->event_date->toDateString() !== Carbon::parse($drive->getOriginal('event_date'))->toDateString()
            || $this->minutes($drive->start_time) !== $this->minutes($drive->getOriginal('start_time'))
            || $this->minutes($drive->end_time) !== $this->minutes($drive->getOriginal('end_time'));
    }

    /**
     * A wall-clock time as 'HH:MM', or null when there is none.
     */
    private function minutes(mixed $time): ?string
    {
        return $time === null || $time === '' ? null : substr((string) $time, 0, 5);
    }

    /**
     * "1 donor is" or "3 donors are".
     */
    private function donors(int $count): string
    {
        return $count === 1 ? '1 donor is' : $count.' donors are';
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MobileEvent $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'facility_name' => $event->facility?->name,
            'location' => $event->location,
            'event_date' => $event->event_date->toDateString(),
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            // Bookings still waiting or under way: what fixes the date and hours.
            'registered_count' => (int) ($event->registered ?? 0),
            // The one planning figure (project owner, 2026-11-12): bookings
            // made ahead that stand, plus the link's list not yet booked.
            'pre_registered_count' => $event->preRegisteredCount(),
            'assigned_staff' => $event->assigned_staff,
            'announcement' => $event->announcement,
            'status' => $event->status(),
            'published_at' => $event->published_at?->toIso8601String(),
            'registration_open' => $event->acceptsRegistrations(),
            'registration_closed_at' => $event->registration_closed_at?->toIso8601String(),
            'cancelled_at' => $event->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $event->cancellation_reason,
            // The registration link's secret part, once there is a link to share.
            'registration_token' => $event->isPublished() ? $event->registration_token : null,
        ];
    }

    /**
     * The facility the caller acts for, resolved from the token rather than input.
     */
    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Build the project's refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }

    /**
     * A 422 refusal that names the form field it belongs to.
     */
    private function refuseField(string $field, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => [$field => [$message]],
        ], 422));
    }
}
