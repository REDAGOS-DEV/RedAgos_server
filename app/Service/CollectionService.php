<?php

namespace App\Service;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\User;
use App\Repository\CollectionRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The Donor/Collection counter: who is expected, who arrived, and what was drawn.
 *
 * This department owns a donation from registration to collection. Laboratory
 * takes it from `collected` onward — see "Who creates a donation and owns its
 * status" in docs/IMPLEMENTATION_DECISIONS.md. Nothing here may write `tested`
 * or `completed`, which is what keeps "cleared for issue to a patient" a
 * laboratory decision.
 */
class CollectionService
{
    /**
     * The transitions this department may perform directly.
     *
     * `collected` is absent on purpose: it is reached by recording a collection,
     * never by setting a status, so a bag can never be marked drawn without the
     * row that says who drew it.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'registered' => ['screening', 'rejected'],
        'screening' => ['rejected'],
    ];

    public function __construct(
        private readonly CollectionRepository $collectionRepository,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Show the day's counter queue: expected appointments and donations in progress.
     *
     * @return array<string, mixed>
     */
    public function queue(User $staff, ?string $date = null): array
    {
        $facility = $this->requireFacility($staff);
        $day = $date ?? OperationalDay::todayAsDate();

        $appointments = $this->collectionRepository->appointmentsForDay($facility->id, $day);

        $open = $this->collectionRepository->paginateDonations(
            $facility->id,
            ['date' => $day, 'open_only' => true],
            100
        );

        return [
            'date' => $day,
            'appointments' => $appointments
                ->map(fn (DonationAppointment $a): array => $this->formatAppointment($a))
                ->all(),
            'in_progress' => collect($open->items())
                ->map(fn (Donation $d): array => $this->formatDonation($d))
                ->all(),
        ];
    }

    /**
     * Verify a scanned QR token and return who is standing at the counter.
     *
     * The raw token is never stored, so the scanned value is hashed and matched
     * against the digest. A hit stamps `last_used_at` but leaves the token
     * usable: a check-in interrupted halfway should not lock a donor out of
     * their own appointment.
     *
     * @return array<string, mixed>
     */
    public function verifyQrToken(User $staff, string $rawToken): array
    {
        $facility = $this->requireFacility($staff);

        $token = $this->collectionRepository->findUsableQrToken(hash('sha256', $rawToken));

        if ($token === null) {
            // One refusal for "never existed", "expired" and "revoked" alike.
            // Distinguishing them would let anyone holding a random string
            // learn whether it was ever a real token.
            $this->auditLogger->record($staff, 'collection.qr_rejected', null, [
                'facility_id' => $facility->id,
            ]);

            throw $this->refuse(404, 'qr_invalid', 'This QR code is not valid. Ask for an ID instead.');
        }

        $this->collectionRepository->stampQrTokenUse($token);

        $donor = $token->donorProfile?->donor
            ?? throw $this->refuse(404, 'donor_not_found', 'This QR code is not linked to a donor.');

        $appointment = $this->collectionRepository->todaysAppointmentFor(
            $donor->id,
            $facility->id,
            OperationalDay::todayAsDate()
        );

        $this->auditLogger->record($staff, 'collection.qr_verified', $donor, [
            'facility_id' => $facility->id,
            'appointment_id' => $appointment?->id,
        ]);

        return [
            'message' => 'QR code verified.',
            'data' => [
                'donor' => $this->formatDonor($donor),
                'appointment' => $appointment ? $this->formatAppointment($appointment) : null,
                'open_donation' => $this->openDonationFor($donor->id, $facility->id),
            ],
        ];
    }

    /**
     * Mark an expected donor as arrived.
     *
     * @return array<string, mixed>
     */
    public function checkIn(User $staff, int $appointmentId): array
    {
        return $this->moveAppointment($staff, $appointmentId, 'confirmed', ['scheduled'], 'collection.checked_in');
    }

    /**
     * Record that an expected donor never arrived.
     *
     * @return array<string, mixed>
     */
    public function markNoShow(User $staff, int $appointmentId): array
    {
        return $this->moveAppointment(
            $staff,
            $appointmentId,
            'no_show',
            ['scheduled', 'confirmed'],
            'collection.no_show'
        );
    }

    /**
     * Open a donation for a donor who is present.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function openDonation(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($payload['donor_uuid'])
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        $donation = DB::transaction(function () use ($facility, $donor, $payload): Donation {
            // One visit at a time. Without this a mis-click at a busy counter
            // opens a second donation, and the donor's history gains a bag that
            // was never drawn.
            if ($existing = $this->donorDirectoryRepository->openDonationAtFacility($donor->id, $facility->id)) {
                throw $this->refuse(
                    409,
                    'donation_already_open',
                    "This donor already has a donation in progress (#{$existing->id})."
                );
            }

            $appointment = null;

            if (isset($payload['appointment_id'])) {
                $appointment = $this->collectionRepository->lockAppointment(
                    (int) $payload['appointment_id'],
                    $facility->id
                ) ?? throw $this->refuse(404, 'appointment_not_found', 'That appointment was not found at your facility.');

                if ((int) $appointment->donor_id !== (int) $donor->id) {
                    throw $this->refuse(422, 'appointment_donor_mismatch', 'That appointment belongs to a different donor.');
                }
            }

            return $this->collectionRepository->createDonation([
                'donor_id' => $donor->id,
                'facility_id' => $facility->id,
                'appointment_id' => $appointment?->id,
                'donation_date' => now(),
                'status' => DonationStatus::Registered,
            ]);
        });

        $this->auditLogger->record($staff, 'collection.donation_opened', $donation, [
            'facility_id' => $facility->id,
            'donor_id' => $donor->id,
        ]);

        return [
            'message' => 'Donation opened for '.$donor->first_name.'.',
            'data' => $this->formatDonation($this->reload($donation, $facility)),
        ];
    }

    /**
     * Move a donation to the next status this department owns.
     *
     * @return array<string, mixed>
     */
    public function advance(User $staff, int $donationId, string $status): array
    {
        $facility = $this->requireFacility($staff);
        $target = DonationStatus::from($status);

        $donation = DB::transaction(function () use ($facility, $donationId, $target): Donation {
            $locked = $this->collectionRepository->lockDonation($donationId, $facility->id)
                ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');

            $this->guardTransition($locked->status, $target);

            $locked->status = $target;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($staff, 'collection.donation_'.$target->value, $donation, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => 'Donation marked '.$target->label().'.',
            'data' => $this->formatDonation($this->reload($donation, $facility)),
        ];
    }

    /**
     * Record the physical collection, which is what moves a donation to `collected`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordCollection(User $staff, int $donationId, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $donation = DB::transaction(function () use ($staff, $facility, $donationId, $payload): Donation {
            $locked = $this->collectionRepository->lockDonation($donationId, $facility->id)
                ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');

            // Checked before the status guard on purpose. Once a collection is
            // recorded the donation is `collected`, so the status guard would
            // otherwise answer a double-click with "record the screening
            // outcome first" — advice that is both confusing and wrong.
            if ($this->collectionRepository->collectionExists($locked->id)) {
                throw $this->refuse(409, 'collection_already_recorded', 'A collection is already recorded for this donation.');
            }

            if ($locked->status !== DonationStatus::Screening) {
                throw $this->refuse(
                    409,
                    'donation_not_screened',
                    'Record the screening outcome before recording a collection.'
                );
            }

            $this->collectionRepository->createCollection([
                'donation_id' => $locked->id,
                // The authenticated staff member, never a name from the request.
                // This is the traceability link between a bag and a person.
                'collected_by' => $staff->id,
                'collection_datetime' => $payload['collection_datetime'] ?? now(),
            ]);

            $locked->status = DonationStatus::Collected;
            $locked->volume_ml = $payload['volume_ml'];
            $locked->save();

            // The visit is over from the counter's point of view.
            if ($locked->appointment_id !== null) {
                $appointment = $this->collectionRepository->lockAppointment(
                    (int) $locked->appointment_id,
                    $facility->id
                );

                if ($appointment !== null && $appointment->status !== 'completed') {
                    $appointment->status = 'completed';
                    $appointment->save();
                }
            }

            return $locked;
        });

        $this->auditLogger->record($staff, 'collection.recorded', $donation, [
            'facility_id' => $facility->id,
            'volume_ml' => $donation->volume_ml,
        ]);

        return [
            'message' => 'Collection recorded. The donation is now with the laboratory.',
            'data' => $this->formatDonation($this->reload($donation, $facility)),
        ];
    }

    /**
     * Page this facility's donations.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listDonations(User $staff, array $filters, int $perPage)
    {
        $facility = $this->requireFacility($staff);

        return $this->collectionRepository
            ->paginateDonations($facility->id, $filters, $perPage)
            ->through(fn (Donation $donation): array => $this->formatDonation($donation));
    }

    /**
     * Refuse a status change this department does not own.
     */
    private function guardTransition(DonationStatus $from, DonationStatus $to): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from->value] ?? [];

        if (in_array($to->value, $allowed, true)) {
            return;
        }

        // Named separately so staff see why, rather than a generic refusal:
        // these two belong to the laboratory and saying so is the useful part.
        if ($to === DonationStatus::Tested || $to === DonationStatus::Completed) {
            throw $this->refuse(
                403,
                'laboratory_owns_status',
                'Only the laboratory may mark a donation tested or completed.'
            );
        }

        throw $this->refuse(
            409,
            'invalid_transition',
            "A donation that is {$from->label()} cannot be marked {$to->label()}."
        );
    }

    /**
     * Move an appointment to a new status, guarding what it may move from.
     *
     * @param  array<int, string>  $from
     * @return array<string, mixed>
     */
    private function moveAppointment(User $staff, int $appointmentId, string $to, array $from, string $action): array
    {
        $facility = $this->requireFacility($staff);

        $appointment = DB::transaction(function () use ($facility, $appointmentId, $to, $from): DonationAppointment {
            $locked = $this->collectionRepository->lockAppointment($appointmentId, $facility->id)
                ?? throw $this->refuse(404, 'appointment_not_found', 'That appointment was not found at your facility.');

            if (! in_array($locked->status, $from, true)) {
                throw $this->refuse(
                    409,
                    'appointment_not_pending',
                    "This appointment is already {$locked->status}."
                );
            }

            $locked->status = $to;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($staff, $action, $appointment, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => 'Appointment updated.',
            'data' => $this->formatAppointment($appointment->fresh(['donorProfile.donor', 'donorProfile.bloodType'])),
        ];
    }

    /**
     * Any donation already in progress for this donor at this facility.
     *
     * @return array<string, mixed>|null
     */
    private function openDonationFor(int $donorId, int $facilityId): ?array
    {
        $open = $this->donorDirectoryRepository->openDonationAtFacility($donorId, $facilityId);

        return $open === null ? null : $this->formatDonation($open);
    }

    /**
     * Re-read a donation with its relations, for the response.
     */
    private function reload(Donation $donation, Facility $facility): Donation
    {
        return $this->collectionRepository->findDonation($donation->id, $facility->id) ?? $donation;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDonation(Donation $donation): array
    {
        return [
            'id' => $donation->id,
            'donation_date' => $donation->donation_date?->toISOString(),
            'status' => $donation->status?->value,
            'status_label' => $donation->status?->label(),
            'owning_department' => $donation->status?->owningDepartment()?->value,
            'volume_ml' => $donation->volume_ml,
            'appointment_id' => $donation->appointment_id,
            'donor' => $donation->relationLoaded('donorProfile') && $donation->donorProfile?->donor
                ? $this->formatDonor($donation->donorProfile->donor)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAppointment(DonationAppointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'appointment_datetime' => $appointment->appointment_datetime?->toISOString(),
            'status' => $appointment->status,
            'event_id' => $appointment->event_id,
            'donor' => $appointment->donorProfile?->donor
                ? $this->formatDonor($appointment->donorProfile->donor)
                : null,
        ];
    }

    /**
     * Identity fields only. The counter needs to know who this is, not their history.
     *
     * @return array<string, mixed>
     */
    private function formatDonor(User $donor): array
    {
        return [
            'uuid' => $donor->uuid,
            'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'blood_type' => $donor->donorProfile?->bloodType?->code,
            'phone' => $donor->phone,
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
}
