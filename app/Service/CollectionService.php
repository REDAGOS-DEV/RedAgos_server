<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonationRecorded;
use App\Notifications\DonorDeferred;
use App\Repository\CollectionRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * `screening` and `collected` are both absent on purpose: each is reached
     * by recording the thing it stands for, never by setting a status. A donor
     * cannot be marked screened without the row saying what was found, and a
     * bag cannot be marked drawn without the row saying who drew it.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'registered' => ['rejected'],
        'screening' => ['rejected'],
    ];

    public function __construct(
        private readonly CollectionRepository $collectionRepository,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EligibilityRuleEvaluator $evaluator
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
        return $this->moveAppointment(
            $staff,
            $appointmentId,
            AppointmentStatus::Confirmed,
            [AppointmentStatus::Scheduled],
            'collection.checked_in'
        );
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
            AppointmentStatus::NoShow,
            [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed],
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function advance(User $staff, int $donationId, string $status, array $payload = []): array
    {
        $facility = $this->requireFacility($staff);
        $target = DonationStatus::from($status);
        $reason = isset($payload['rejection_reason']) ? trim((string) $payload['rejection_reason']) : null;

        $donation = DB::transaction(function () use ($facility, $donationId, $target, $reason): Donation {
            $locked = $this->collectionRepository->lockDonation($donationId, $facility->id)
                ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');

            $this->guardTransition($locked->status, $target);

            $locked->status = $target;

            if ($target === DonationStatus::Rejected) {
                $locked->rejection_reason = $reason;
            }

            $locked->save();

            // A donor turned away at the counter has finished their visit just
            // as much as one who donated, so the booking closes either way.
            if ($target->isTerminal()) {
                $this->closeAppointmentFor($locked, $facility->id);
            }

            return $locked;
        });

        $this->auditLogger->record($staff, 'collection.donation_'.$target->value, $donation, [
            'facility_id' => $facility->id,
            'rejection_reason' => $target === DonationStatus::Rejected ? $reason : null,
        ]);

        return [
            'message' => 'Donation marked '.$target->label().'.',
            'data' => $this->formatDonation($this->reload($donation, $facility)),
        ];
    }

    /**
     * Record the on-site screening outcome a qualified professional reported.
     *
     * This is what moves a donation to `screening`, the same way recording a
     * collection is what moves it to `collected`. A deferral ends the visit:
     * the donation is rejected and the appointment closes.
     *
     * Nothing here judges the vitals. The outcome is the professional's verdict,
     * transcribed — see the scope boundary in docs/BLOOD-CENTER.md.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordScreening(User $staff, int $donationId, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $outcome = ScreeningOutcome::from($payload['outcome']);

        $donation = DB::transaction(function () use ($staff, $facility, $donationId, $payload, $outcome): Donation {
            $locked = $this->collectionRepository->lockDonation($donationId, $facility->id)
                ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');

            // A correction is allowed while the donor is still at the counter,
            // but not once the bag has been drawn: the screening is what let
            // the collection happen, so rewriting it afterwards would rewrite
            // the justification for something already done.
            if (! in_array($locked->status, [DonationStatus::Registered, DonationStatus::Screening], true)) {
                throw $this->refuse(
                    409,
                    'screening_not_amendable',
                    "A donation that is {$locked->status->label()} can no longer have its screening recorded."
                );
            }

            $deferralReason = isset($payload['deferral_reason'])
                ? trim((string) $payload['deferral_reason'])
                : null;

            $this->collectionRepository->upsertScreening($locked->id, [
                'facility_id' => $facility->id,
                // The authenticated staff member, never a name from the request.
                'recorded_by' => $staff->id,
                'outcome' => $outcome,
                'deferral_reason' => $outcome === ScreeningOutcome::Deferred ? $deferralReason : null,
                'systolic_bp' => $payload['systolic_bp'] ?? null,
                'diastolic_bp' => $payload['diastolic_bp'] ?? null,
                'pulse_bpm' => $payload['pulse_bpm'] ?? null,
                'temperature_c' => $payload['temperature_c'] ?? null,
                'weight_kg' => $payload['weight_kg'] ?? null,
                'haemoglobin_g_dl' => $payload['haemoglobin_g_dl'] ?? null,
                'notes' => isset($payload['notes']) ? trim((string) $payload['notes']) : null,
                'screened_at' => $payload['screened_at'] ?? now(),
            ]);

            if ($outcome->permitsCollection()) {
                $locked->status = DonationStatus::Screening;
                $locked->rejection_reason = null;
                $locked->save();

                return $locked;
            }

            $locked->status = DonationStatus::Rejected;
            $locked->rejection_reason = $deferralReason;
            $locked->save();

            // The donor has been sent home, so their booking is finished too.
            $this->closeAppointmentFor($locked, $facility->id);

            return $locked;
        });

        $this->auditLogger->record($staff, 'collection.screening_recorded', $donation, [
            'facility_id' => $facility->id,
            'outcome' => $outcome->value,
        ]);

        if (! $outcome->permitsCollection()) {
            $this->notifyDonor(
                $donation,
                fn (User $donor): DonorDeferred => new DonorDeferred($donation, $donation->rejection_reason),
                'deferral notice'
            );
        }

        return [
            'message' => $outcome->permitsCollection()
                ? 'Screening recorded. The donor may proceed to collection.'
                : 'Screening recorded. The donor has been deferred.',
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

            // Both halves matter. The status alone could in principle be reached
            // by some future path that skips the record, and the record is the
            // thing that says a professional cleared this donor to give blood.
            if ($locked->status !== DonationStatus::Screening
                || ! $this->collectionRepository->screeningExists($locked->id)) {
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
            $this->closeAppointmentFor($locked, $facility->id);

            return $locked;
        });

        $this->auditLogger->record($staff, 'collection.recorded', $donation, [
            'facility_id' => $facility->id,
            'volume_ml' => $donation->volume_ml,
        ]);

        $this->notifyDonor(
            $donation,
            fn (User $donor): DonationRecorded => new DonationRecorded(
                $donation,
                $this->evaluator->nextEligibleDate($donation->donation_date)
            ),
            'donation receipt'
        );

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

        // Both of these are reached by recording something, so point staff at
        // the action rather than refusing without telling them what to do.
        if ($to === DonationStatus::Screening) {
            throw $this->refuse(
                409,
                'screening_not_recorded',
                'Record the screening outcome rather than setting this status.'
            );
        }

        if ($to === DonationStatus::Collected) {
            throw $this->refuse(
                409,
                'collection_not_recorded',
                'Record the collection rather than setting this status.'
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
     * @param  array<int, AppointmentStatus>  $from
     * @return array<string, mixed>
     */
    private function moveAppointment(
        User $staff,
        int $appointmentId,
        AppointmentStatus $to,
        array $from,
        string $action
    ): array {
        $facility = $this->requireFacility($staff);

        $appointment = DB::transaction(function () use ($facility, $appointmentId, $to, $from): DonationAppointment {
            $locked = $this->collectionRepository->lockAppointment($appointmentId, $facility->id)
                ?? throw $this->refuse(404, 'appointment_not_found', 'That appointment was not found at your facility.');

            if (! in_array($locked->status, $from, true)) {
                throw $this->refuse(
                    409,
                    'appointment_not_pending',
                    "This appointment is already {$locked->status->value}."
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
     * Tell the donor what was recorded about their visit.
     *
     * After the commit, and never allowed to fail the visit: the bag is already
     * drawn or the donor already sent home, so a mailer error here is a message
     * to chase up rather than a reason to refuse a request that has succeeded.
     *
     * @param  callable(User): Notification  $build
     */
    private function notifyDonor(Donation $donation, callable $build, string $what): void
    {
        $donor = $donation->donorProfile?->donor;

        if ($donor === null) {
            return;
        }

        try {
            $donor->notify($build($donor));
        } catch (Throwable $exception) {
            Log::warning("Could not send the {$what}.", [
                'donor_id' => $donor->id,
                'donation_id' => $donation->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Close the appointment a finished donation was booked against.
     *
     * Called for every terminal outcome, not only a successful draw. A donor
     * deferred at the counter has finished their visit just as surely as one
     * who donated, and leaving the appointment `confirmed` would hold its slot
     * and keep the donor in the day's queue for good.
     *
     * Must run inside the caller's transaction, with the donation already locked.
     */
    private function closeAppointmentFor(Donation $donation, int $facilityId): void
    {
        if ($donation->appointment_id === null) {
            return;
        }

        $appointment = $this->collectionRepository->lockAppointment(
            (int) $donation->appointment_id,
            $facilityId
        );

        if ($appointment === null || $appointment->status === AppointmentStatus::Completed) {
            return;
        }

        $appointment->status = AppointmentStatus::Completed;
        $appointment->save();
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
        $screening = $donation->relationLoaded('screening')
            ? $donation->screening
            : $donation->screening()->first();

        return [
            'id' => $donation->id,
            'donation_date' => $donation->donation_date?->toISOString(),
            'status' => $donation->status?->value,
            'status_label' => $donation->status?->label(),
            'owning_department' => $donation->status?->owningDepartment()?->value,
            'volume_ml' => $donation->volume_ml,
            'rejection_reason' => $donation->rejection_reason,
            'screening' => $screening === null ? null : [
                'outcome' => $screening->outcome?->value,
                'outcome_label' => $screening->outcome?->label(),
                'deferral_reason' => $screening->deferral_reason,
                'systolic_bp' => $screening->systolic_bp,
                'diastolic_bp' => $screening->diastolic_bp,
                'pulse_bpm' => $screening->pulse_bpm,
                'temperature_c' => $screening->temperature_c,
                'weight_kg' => $screening->weight_kg,
                'haemoglobin_g_dl' => $screening->haemoglobin_g_dl,
                'notes' => $screening->notes,
                'screened_at' => $screening->screened_at?->toISOString(),
            ],
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
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
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
