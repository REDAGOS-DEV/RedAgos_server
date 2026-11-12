<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Repository\AppointmentRepository;
use App\Repository\DonorDirectoryRepository;
use App\Repository\DrivePreRegistrationRepository;
use App\Repository\MobileEventRepository;
use App\Support\OperationalDay;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Checking people in at a drive, on its day (drive pre-registration, phase 3).
 *
 * Everyone who turns up goes through the appointment path, so the counter
 * links their donation to the drive the way it does for a booked donor
 * (donation -> appointment -> drive), and no drive column is ever needed on
 * a donation:
 *  - someone on the registration link's list, once staff have linked them to
 *    a donor record, is checked in on their own booking, or on one made now;
 *  - a walk-in is checked in on a booking made now and marked `walk_in`, so
 *    they are not counted as pre-registered.
 *
 * Check-in only: the counter then screens the donor and decides. Nothing here
 * screens, defers or records a donation.
 */
class DriveCheckInService
{
    public function __construct(
        private readonly MobileEventRepository $drives,
        private readonly DrivePreRegistrationRepository $preRegistrations,
        private readonly AppointmentRepository $appointments,
        private readonly DonorDirectoryRepository $donors,
        private readonly DrivePreRegistrationService $preRegistrationService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Check in someone from the drive's list who is linked to a donor record.
     *
     * @return array<string, mixed>
     */
    public function checkInEntry(User $staff, int $driveId, int $entryId): array
    {
        $facility = $this->requireFacility($staff);

        [$drive, $appointment, $donor] = DB::transaction(function () use ($staff, $facility, $driveId, $entryId): array {
            $drive = $this->lockDriveDay($facility, $driveId);

            $entry = $this->preRegistrations->lockForDrive($entryId, $drive->id)
                ?? throw $this->refuse(404, 'pre_registration_not_found', 'That person was not found on this drive\'s list.');

            if (! $entry->status->isOnList() || $entry->anonymised_at !== null) {
                throw $this->refuse(409, 'already_withdrawn', 'This person is off the list.');
            }

            $donor = $entry->donor_id !== null ? $this->donors->findDonor((string) $entry->donor?->uuid) : null;

            if ($donor === null) {
                throw $this->refuse(409, 'not_matched', 'Link this person to a donor record first, or register them as a donor.');
            }

            $appointment = $this->arrive($staff, $facility, $drive, $donor, walkIn: false);

            $entry->forceFill(['appointment_id' => $appointment->id])->save();

            return [$drive, $appointment, $donor];
        });

        return $this->checkedIn($staff, $drive, $appointment, $donor, 'drive.checked_in');
    }

    /**
     * Check in someone who came to the drive without registering. A donor
     * on the drive's list or booked for it is checked in as such instead.
     *
     * @return array<string, mixed>
     */
    public function walkIn(User $staff, int $driveId, string $donorUuid): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donors->findDonor($donorUuid)
            ?? throw new HttpResponseException(response()->json([
                'message' => 'That donor was not found.',
                'code' => 'donor_not_found',
                'errors' => ['donor_uuid' => ['That donor was not found.']],
            ], 422));

        [$drive, $appointment] = DB::transaction(function () use ($staff, $facility, $driveId, $donor): array {
            $drive = $this->lockDriveDay($facility, $driveId);

            // Someone staff linked to this donor on the list registered ahead.
            $listed = $drive->preRegistrations()->onList()->where('donor_id', $donor->id)->exists();

            return [$drive, $this->arrive($staff, $facility, $drive, $donor, walkIn: ! $listed)];
        });

        return $this->checkedIn($staff, $drive, $appointment, $donor, $appointment->walk_in ? 'drive.walk_in' : 'drive.checked_in');
    }

    /**
     * The donor's booking for the drive, checked in: their own if they made
     * one, or one made now. A donor holding a booking anywhere else is
     * refused, as a second booking is everywhere else.
     */
    private function arrive(User $staff, Facility $facility, MobileEvent $drive, User $donor, bool $walkIn): DonationAppointment
    {
        // Any live booking for this drive, walk-in or not: a second press
        // checks in the same booking rather than making another.
        $booking = $this->appointments->liveDriveBooking($drive->id, $donor->id);

        if ($booking !== null) {
            if ($booking->status === AppointmentStatus::Scheduled) {
                $booking->status = AppointmentStatus::Confirmed;
                $booking->save();
            }

            return $booking;
        }

        if ($this->appointments->hasActiveAppointment($donor->id)) {
            throw $this->refuse(409, 'duplicate_appointment', 'This donor already has an upcoming appointment. Cancel or move that one first.');
        }

        return $this->appointments->create([
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'event_id' => $drive->id,
            'walk_in' => $walkIn,
            'appointment_datetime' => now(),
            'status' => AppointmentStatus::Confirmed,
            'booked_by' => $staff->id,
        ]);
    }

    /**
     * Tie the donor's list entries to the booking, audit the check-in, and
     * answer with where the counter takes over.
     *
     * @return array<string, mixed>
     */
    private function checkedIn(User $staff, MobileEvent $drive, DonationAppointment $appointment, User $donor, string $action): array
    {
        $this->preRegistrationService->tieToBooking($drive, $donor, $appointment->id);

        $this->auditLogger->record($staff, $action, $appointment, [
            'facility_id' => $drive->facility_id,
            'mobile_event_id' => $drive->id,
            'donor_id' => $donor->id,
        ]);

        return [
            'message' => trim($donor->first_name.' '.$donor->last_name).' is checked in. Continue at the Donation counter.',
            'data' => [
                'appointment_id' => $appointment->id,
                'donor_uuid' => (string) $donor->uuid,
                'walk_in' => (bool) $appointment->walk_in,
            ],
        ];
    }

    /**
     * One of the facility's drives, locked, on its own day and not cancelled.
     * A draft may be held, as a private drive is.
     */
    private function lockDriveDay(Facility $facility, int $driveId): MobileEvent
    {
        $drive = $this->drives->lockForFacility($driveId, $facility->id)
            ?? throw $this->refuse(404, 'drive_not_found', 'That drive was not found at your facility.');

        if ($drive->isCancelled()) {
            throw $this->refuse(409, 'drive_cancelled', 'This drive was cancelled.');
        }

        if ($drive->event_date->toDateString() !== OperationalDay::todayAsDate()) {
            throw $this->refuse(409, 'not_drive_day', 'People are checked in on the drive\'s own day.');
        }

        return $drive;
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
}
