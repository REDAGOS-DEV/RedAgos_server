<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Repository\BloodCenterAppointmentRepository;
use App\Repository\DonorDeferralRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Appointments page (project owner, 2026-10-17): one list of a centre's
 * bookings, at the centre and at its drives, and the staff side of booking —
 * a donor who phones in is booked by the desk.
 *
 * A drive is a mobile blood drive this centre runs
 * (MobileEvent). The booking rules are AppointmentService's, the same ones
 * the donor portal obeys; check-in and no-show stay CollectionService's.
 */
class BloodCenterAppointmentService
{
    public function __construct(
        private readonly BloodCenterAppointmentRepository $appointments,
        private readonly AppointmentService $appointmentService,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly DonorDeferralRepository $donorDeferralRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        return $this->appointments
            ->paginate($facility->id, $filters, $perPage)
            ->through(fn (DonationAppointment $a): array => $this->format($a, $staff));
    }

    /**
     * What the New Appointment form offers: the slots on a date, at the centre
     * or at one of its drives, and the drives themselves.
     *
     * @return array<string, mixed>
     */
    public function options(User $staff, ?string $date, ?int $driveId): array
    {
        $facility = $this->requireFacility($staff);
        $drive = $driveId !== null ? $this->appointmentService->ownUpcomingDrive($facility, $driveId) : null;
        $day = $drive?->event_date->toDateString() ?? $date ?? OperationalDay::todayAsDate();

        return [
            'date' => $day,
            'center_name' => $facility->name,
            'accepting' => (bool) $facility->is_accepting_donations,
            'slots' => $this->appointmentService->slotsFor($facility, $day, $drive),
            'drives' => $this->appointments->upcomingDrives($facility->id)
                ->map(fn (MobileEvent $drive): array => [
                    'id' => $drive->id,
                    'name' => $drive->name,
                    'location' => $drive->location,
                    'date' => $drive->event_date?->toDateString(),
                    'start_time' => $drive->start_time ? substr((string) $drive->start_time, 0, 5) : null,
                    'end_time' => $drive->end_time ? substr((string) $drive->end_time, 0, 5) : null,
                    'max_capacity' => $drive->max_capacity,
                    'registered' => (int) $drive->registered,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Book an appointment for a donor on the phone.
     *
     * Any donor RedAgos knows may be booked: the desk finds them on its own
     * Donors list, or by the exact ID they read out, and the booking itself
     * makes them this centre's donor. A suspended or closed account is not.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function book(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor((string) $payload['donor_uuid'])
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        if (in_array($donor->account_status, [AccountStatus::Suspended, AccountStatus::Deactivated], true)) {
            throw $this->refuse(422, 'account_closed', 'This donor\'s account is suspended or closed.');
        }

        $appointment = $this->appointmentService->bookOnBehalf(
            $staff,
            $facility,
            $donor,
            $payload,
            deferred: $this->donorDeferralRepository->standingDeferralFor($donor->id) !== null
        );

        $this->auditLogger->record($staff, 'appointment.booked_by_staff', $appointment, [
            'facility_id' => $facility->id,
            'donor_id' => $donor->id,
            'drive_id' => $appointment->event_id,
        ]);

        return [
            'message' => 'Appointment booked for '.trim($donor->first_name.' '.$donor->last_name).'.',
            'data' => $this->format($appointment->fresh(['facility', 'donorProfile.donor', 'donorProfile.bloodType', 'mobileEvent', 'donation', 'bookedBy']), $staff),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Mark a booking collected from the Appointments list (project owner,
     * 2026-10-17): the ✓ on a scheduled or in-progress row.
     *
     * It closes the booking the way recording a collection at the Donations
     * counter closes it, and nothing more. It writes no donation: the bag, the
     * donor's history and their next eligible date still come from recording
     * the visit at the counter, which can be done against this booking
     * afterwards (the counter accepts a completed booking and leaves it
     * completed). Not for a day still to come.
     *
     * @return array<string, mixed>
     */
    public function markCollected(User $staff, int $id): array
    {
        $facility = $this->requireFacility($staff);

        $appointment = DB::transaction(function () use ($facility, $id): DonationAppointment {
            $locked = $this->appointments->lockForFacility($id, $facility->id)
                ?? throw $this->refuse(404, 'appointment_not_found', 'That appointment was not found at your facility.');

            if (! in_array($locked->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true)) {
                throw $this->refuse(409, 'appointment_not_pending', 'This appointment is already '.$locked->status->label().'.');
            }

            if (CarbonImmutable::instance($locked->appointment_datetime)->toDateString() > OperationalDay::todayAsDate()) {
                throw $this->refuse(422, 'appointment_not_due', 'An appointment can be marked collected from its day on.');
            }

            $locked->status = AppointmentStatus::Completed;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($staff, 'appointment.marked_collected', $appointment, [
            'facility_id' => $facility->id,
            'donor_id' => $appointment->donor_id,
        ]);

        return [
            'message' => trim(($appointment->donorProfile?->donor?->first_name ?? 'The donor').' marked as collected.'),
            'data' => $this->format($appointment->fresh(['facility', 'donorProfile.donor', 'donorProfile.bloodType', 'mobileEvent', 'donation', 'bookedBy']), $staff),
        ];
    }

    public function cancel(User $staff, int $id): array
    {
        $facility = $this->requireFacility($staff);

        $appointment = DB::transaction(function () use ($facility, $id): DonationAppointment {
            $locked = $this->appointments->lockForFacility($id, $facility->id)
                ?? throw $this->refuse(404, 'appointment_not_found', 'That appointment was not found at your facility.');

            return $this->appointmentService->cancelOnBehalf($locked);
        });

        $this->auditLogger->record($staff, 'appointment.cancelled_by_staff', $appointment, ['facility_id' => $facility->id]);

        return [
            'message' => 'Appointment cancelled.',
            'data' => $this->format($appointment->fresh(['facility', 'donorProfile.donor', 'donorProfile.bloodType', 'mobileEvent', 'donation', 'bookedBy']), $staff),
        ];
    }

    /**
     * Remove a booking entered by mistake.
     *
     * Never one a visit was opened against: the donation keeps a link to its
     * appointment, and deleting the row would quietly cut it. Nor one under
     * way at the counter. What is removed is written to the audit trail
     * first, so the record survives in the one place that keeps history.
     *
     * @return array<string, string>
     */
    public function delete(User $staff, int $id): array
    {
        $facility = $this->requireFacility($staff);

        DB::transaction(function () use ($facility, $id, $staff): void {
            $locked = $this->appointments->lockForFacility($id, $facility->id)
                ?? throw $this->refuse(404, 'appointment_not_found', 'That appointment was not found at your facility.');

            if (! $this->deletable($locked)) {
                throw $this->refuse(
                    409,
                    'appointment_has_visit',
                    'This appointment has a visit recorded against it, so it is kept with that record.'
                );
            }

            $this->auditLogger->record($staff, 'appointment.deleted', $locked, [
                'facility_id' => $facility->id,
                'donor_id' => $locked->donor_id,
                'drive_id' => $locked->event_id,
                'appointment_datetime' => $locked->appointment_datetime?->toISOString(),
                'status' => $locked->status->value,
            ]);

            $locked->delete();
        });

        return ['message' => 'Appointment deleted.'];
    }

    /**
     * The filtered list as a CSV for Excel, with the same filters as the page.
     *
     * @param  array<string, mixed>  $filters
     */
    public function csv(User $staff, array $filters): StreamedResponse
    {
        $facility = $this->requireFacility($staff);
        $query = $this->appointments->query($facility->id, $filters);

        $this->auditLogger->record($staff, 'appointments.exported', $facility, [
            'rows' => (clone $query)->count(),
            'filters' => array_intersect_key($filters, array_flip(['search', 'from', 'to', 'status'])),
        ]);

        return response()->streamDownload(function () use ($query, $staff): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['AppointmentID', 'Donor', 'DonorID', 'BloodGroup', 'Phone', 'Date', 'Slot', 'DriveCenter', 'Status', 'BookedBy', 'Notes', 'BookedOn'], escape: '');

            $query->chunk(300, function ($rows) use ($out, $staff): void {
                foreach ($rows as $appointment) {
                    $row = $this->format($appointment, $staff);

                    fputcsv($out, array_map(fn ($v): string => $this->cell($v), [
                        $row['code'],
                        $row['donor']['full_name'] ?? '',
                        $row['donor']['donor_code'] ?? '',
                        $row['donor']['blood_type'] ?? '',
                        $this->phone($row['donor']['phone'] ?? null),
                        $row['date'],
                        $row['slot_start'].' - '.$row['slot_end'],
                        $row['place'],
                        $row['status_label'],
                        $row['booked_by'] ?? 'Donor (portal)',
                        $row['notes'],
                        substr((string) $row['booked_at'], 0, 10),
                    ]), escape: '');
                }
            });

            fclose($out);
        }, 'appointments-'.Str::slug($facility->name).'-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * One row of the list. `can` says which of the row's actions apply now,
     * so the page and the server read the rules from one place.
     *
     * @return array<string, mixed>
     */
    private function format(DonationAppointment $appointment, User $staff): array
    {
        // Read as booked: the portal and the slot grid store a slot as wall
        // clock in the application's timezone (Manila in deployment, which is
        // also the operational one), so no conversion here.
        $at = CarbonImmutable::instance($appointment->appointment_datetime);
        $today = OperationalDay::todayAsDate();
        $date = $at->toDateString();
        $interval = max(5, (int) ($appointment->facility?->slot_interval_minutes ?? 30));
        $donor = $appointment->donorProfile?->donor;
        $status = $appointment->status;

        // A collected visit and a deferred one both close the appointment as
        // completed; the donation behind it tells which.
        $label = $status === AppointmentStatus::Completed && $appointment->donation?->status === DonationStatus::Rejected
            ? 'Deferred'
            : $status->label();

        return [
            'id' => $appointment->id,
            'code' => 'APT-'.str_pad((string) $appointment->id, 6, '0', STR_PAD_LEFT),
            'appointment_datetime' => $appointment->appointment_datetime?->toISOString(),
            'date' => $date,
            'slot_start' => $at->format('H:i'),
            'slot_end' => $at->addMinutes($interval)->format('H:i'),
            'status' => $status->value,
            'status_label' => $label,
            'drive' => $appointment->mobileEvent ? ['id' => $appointment->mobileEvent->id, 'name' => $appointment->mobileEvent->name] : null,
            // The drive's name, or the centre's own (project owner, 2026-10-17).
            'place' => $appointment->mobileEvent?->name ?? $appointment->facility?->name,
            'notes' => $appointment->notes,
            'booked_by' => $appointment->bookedBy ? $appointment->bookedBy->signatureName() : null,
            'booked_at' => $appointment->created_at?->toISOString(),
            'donor' => $donor === null ? null : [
                'uuid' => $donor->uuid,
                'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
                'full_name' => trim($donor->first_name.' '.$donor->last_name),
                'blood_type' => $appointment->donorProfile?->bloodType?->code,
                'phone' => $donor->phone,
            ],
            'can' => [
                // The ✓: mark the booking collected, from the appointment day on
                // (a late arrival included), whether or not it was checked in
                // first (project owner, 2026-10-17). Not before the day.
                'collect' => in_array($status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true) && $date <= $today && $staff->can('appointments.verify'),
                'no_show' => in_array($status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true) && $date <= $today && $staff->can('appointments.verify'),
                // Any scheduled booking, past or future: a donor may call to
                // say they could not come, which is theirs to tell, not a no-show.
                'cancel' => $status === AppointmentStatus::Scheduled && $staff->can('appointments.manage'),
                'delete' => $this->deletable($appointment) && $staff->can('appointments.delete'),
                'open_counter' => $status === AppointmentStatus::Confirmed,
            ],
        ];
    }

    private function deletable(DonationAppointment $appointment): bool
    {
        $hasVisit = $appointment->relationLoaded('donation')
            ? $appointment->donation !== null
            : $appointment->donation()->exists();

        return in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)
            && ! $hasVisit;
    }

    private function phone(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        return preg_match('/^\+63(9\d{2})(\d{3})(\d{4})$/', $phone, $m) === 1 ? "0{$m[1]} {$m[2]} {$m[3]}" : $phone;
    }

    private function cell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
