<?php

namespace App\Service;

use App\Enums\PreRegistrationSource;
use App\Enums\PreRegistrationStatus;
use App\Models\DrivePreRegistration;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\DriveCancelledForRegistrant;
use App\Notifications\DriveRescheduledForRegistrant;
use App\Repository\DrivePreRegistrationRepository;
use App\Support\AccountIdentity;
use App\Support\OperationalDay;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * A drive's registration link and the entries it builds (drive
 * pre-registration; project owner, 2026-11-11 and 2026-11-12).
 *
 * Staff create and publish the drive; people without a portal account put
 * themselves on its list, and partner organisations put their people on it,
 * through the one link. There is no limit and no health question: a person
 * without an account is screened in person at the drive. Staff take people
 * off the list and confirm which entries are existing donors; the drive's
 * roster (DriveRosterService) is what they read.
 *
 * Planning only. Nothing here creates an account, an appointment, a screening
 * or a donation, and nothing here says anyone is eligible, attended or gave.
 */
class DrivePreRegistrationService
{
    public function __construct(
        private readonly DrivePreRegistrationRepository $repository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * What the registration link shows: the drive's public facts. No counts,
     * no ids, nobody's details.
     *
     * @return array<string, mixed>
     */
    public function publicDrive(string $token): array
    {
        $drive = $this->repository->findPublishedByToken($token) ?? throw $this->linkNotFound();

        return [
            'drive' => [
                'name' => $drive->name,
                'location' => $drive->location,
                'event_date' => $drive->event_date->toDateString(),
                'start_time' => $drive->start_time ? substr($drive->start_time, 0, 5) : null,
                'end_time' => $drive->end_time ? substr($drive->end_time, 0, 5) : null,
                'announcement' => $drive->announcement,
                'centre_name' => $drive->facility?->name,
                'state' => $this->linkState($drive),
                'cancellation_reason' => $drive->isCancelled() ? $drive->cancellation_reason : null,
            ],
            'group_max_people' => (int) config('donation.drive_group_max_people'),
        ];
    }

    /**
     * Put a person on the drive's list, as they typed themselves in.
     *
     * Someone already on the list (same email or mobile number) is not added
     * twice, and is answered exactly as a new entry is, so the link cannot be
     * used to learn who registered.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerSelf(string $token, array $payload): array
    {
        $person = $this->person($payload);

        [$drive, $entry] = DB::transaction(function () use ($token, $person): array {
            $drive = $this->lockOpenDrive($token);
            $onList = $this->repository->contactsOnList($drive->id);

            if ($this->isListed($person, $onList)) {
                return [$drive, null];
            }

            return [$drive, $this->repository->create([
                ...$person,
                'mobile_event_id' => $drive->id,
                'source' => PreRegistrationSource::Individual,
                'status' => PreRegistrationStatus::PreRegistered,
                'consent_at' => now(),
            ])];
        });

        if ($entry !== null) {
            $this->auditLogger->record(null, 'drive.pre_registered', $entry, [
                'facility_id' => $drive->facility_id,
                'mobile_event_id' => $drive->id,
                'source' => PreRegistrationSource::Individual->value,
            ]);
        }

        return [
            'message' => 'You are on the list for '.$drive->name.'.',
            'drive_name' => $drive->name,
        ];
    }

    /**
     * Put a partner organisation's people on the drive's list.
     *
     * Each person meets the rules a person registering themselves meets. Anyone
     * already on the list, or listed twice in the batch, is added once; the
     * answer counts what was received, not what was new, for the same reason
     * registerSelf() answers a duplicate as it answers anyone.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerGroup(string $token, array $payload): array
    {
        $people = array_map(fn (array $person): array => $this->person($person), $payload['people']);

        [$drive, $submission, $added] = DB::transaction(function () use ($token, $payload, $people): array {
            $drive = $this->lockOpenDrive($token);
            $onList = $this->repository->contactsOnList($drive->id);

            $submission = $this->repository->createSubmission([
                'mobile_event_id' => $drive->id,
                'organisation_name' => trim((string) $payload['organisation_name']),
                'contact_name' => trim((string) $payload['contact_name']),
                'contact_phone' => $this->phone($payload['contact_phone'] ?? null),
                'contact_email' => $this->email($payload['contact_email'] ?? null),
                'consent_at' => now(),
            ]);

            $added = 0;

            foreach ($people as $person) {
                if ($this->isListed($person, $onList)) {
                    continue;
                }

                $this->repository->create([
                    ...$person,
                    'mobile_event_id' => $drive->id,
                    'source' => PreRegistrationSource::Partner,
                    'status' => PreRegistrationStatus::PreRegistered,
                    'partner_submission_id' => $submission->id,
                ]);

                $onList['emails'] += $person['email'] !== null ? [$person['email'] => true] : [];
                $onList['phones'] += $person['phone'] !== null ? [$person['phone'] => true] : [];
                $added++;
            }

            return [$drive, $submission, $added];
        });

        $this->auditLogger->record(null, 'drive.group_submitted', $submission, [
            'facility_id' => $drive->facility_id,
            'mobile_event_id' => $drive->id,
            'rows' => $added,
            'skipped' => count($people) - $added,
        ]);

        $received = count($people);

        return [
            'message' => ($received === 1 ? '1 person was' : $received.' people were').' received for '.$drive->name.'.',
            'drive_name' => $drive->name,
            'received' => $received,
        ];
    }

    /**
     * Take a person off the drive's list, at their request.
     *
     * @return array<string, mixed>
     */
    public function withdraw(User $staff, int $driveId, int $entryId): array
    {
        return $this->change($staff, $driveId, $entryId, 'drive.pre_registration_withdrawn', function (DrivePreRegistration $entry) use ($staff): void {
            if (! $entry->status->isOnList()) {
                throw $this->refuse(409, 'already_withdrawn', 'This person is already off the list.');
            }

            if ($entry->appointment_id !== null) {
                throw $this->refuse(409, 'pre_registration_booked', 'This person has a booking. Cancel the booking on the Appointments page instead.');
            }

            $entry->forceFill([
                'status' => PreRegistrationStatus::Withdrawn,
                'withdrawn_by' => $staff->id,
                'withdrawn_at' => now(),
            ]);
        }, 'Taken off the list.');
    }

    /**
     * Confirm that an entry is an existing donor. Staff decide; a matching
     * email or number is only ever a suggestion. A donor already booked for
     * the drive is then counted once, by the booking.
     *
     * @return array<string, mixed>
     */
    public function match(User $staff, int $driveId, int $entryId, string $donorUuid): array
    {
        $donor = $this->repository->findDonor($donorUuid)
            ?? throw new HttpResponseException(response()->json([
                'message' => 'That donor was not found.',
                'code' => 'donor_not_found',
                'errors' => ['donor_uuid' => ['That donor was not found.']],
            ], 422));

        return $this->change($staff, $driveId, $entryId, 'drive.pre_registration_matched', function (DrivePreRegistration $entry) use ($staff, $donor): void {
            if (! $entry->status->isOnList()) {
                throw $this->refuse(409, 'already_withdrawn', 'This person is off the list.');
            }

            $entry->forceFill([
                'donor_id' => $donor->id,
                'matched_by' => $staff->id,
                'matched_at' => now(),
                'appointment_id' => $entry->appointment_id
                    ?? $this->repository->standingBooking((int) $entry->mobile_event_id, $donor->id)?->id,
            ]);
        }, 'Linked to the donor.', ['donor_id' => $donor->id]);
    }

    /**
     * Undo a match made in error.
     *
     * @return array<string, mixed>
     */
    public function unmatch(User $staff, int $driveId, int $entryId): array
    {
        return $this->change($staff, $driveId, $entryId, 'drive.pre_registration_unmatched', function (DrivePreRegistration $entry): void {
            if ($entry->donor_id === null) {
                throw $this->refuse(409, 'not_matched', 'This person is not linked to a donor.');
            }

            if ($entry->appointment_id !== null) {
                throw $this->refuse(409, 'pre_registration_booked', 'This person has a booking, so the link to the donor stays.');
            }

            $entry->forceFill(['donor_id' => null, 'matched_by' => null, 'matched_at' => null]);
        }, 'Unlinked from the donor.');
    }

    /**
     * Tie a donor's entries on a drive's list to the booking now made for
     * them (the portal, the desk, or a check-in at the drive), so the person
     * is counted once.
     */
    public function tieToBooking(MobileEvent|int $drive, User $donor, int $appointmentId): void
    {
        $this->repository->tieToBooking(
            $drive instanceof MobileEvent ? $drive->id : $drive,
            $donor->id,
            $donor->hasVerifiedEmail() ? $donor->email : null,
            $appointmentId
        );
    }

    /**
     * Tell the people on a cancelled drive's list, by the email and mobile
     * number they gave. Those already booked hear through their booking
     * (DriveCancelled). Never allowed to undo the cancellation.
     */
    public function notifyDriveCancelled(MobileEvent $drive): int
    {
        return $this->notifyList(
            $drive,
            fn (DrivePreRegistration $entry): DriveCancelledForRegistrant => new DriveCancelledForRegistrant($drive, $entry->first_name),
            'Could not tell a person on the list that their drive was cancelled.'
        );
    }

    /**
     * Tell the people on a moved drive's list its new date and hours (project
     * owner, 2026-11-12). Those already booked hear through their booking
     * (DriveRescheduled). Never allowed to undo the move.
     */
    public function notifyDriveRescheduled(MobileEvent $drive): int
    {
        return $this->notifyList(
            $drive,
            fn (DrivePreRegistration $entry): DriveRescheduledForRegistrant => new DriveRescheduledForRegistrant($drive, $entry->first_name),
            'Could not tell a person on the list that their drive moved.'
        );
    }

    /**
     * Send a notice to everyone still on a drive's list and not yet booked, by
     * the email and mobile number on their entry. Only those a notice can
     * reach: an email, or a mobile number while SMS is set up.
     *
     * @param  callable(DrivePreRegistration): BaseNotification  $notice
     */
    private function notifyList(MobileEvent $drive, callable $notice, string $failure): int
    {
        $sent = 0;
        $texts = app(SemaphoreSms::class)->isConfigured();

        DrivePreRegistration::query()
            ->where('mobile_event_id', $drive->id)
            ->onList()
            ->whereNull('appointment_id')
            ->whereNull('anonymised_at')
            ->get(['id', 'first_name', 'email', 'phone'])
            ->filter(fn (DrivePreRegistration $entry): bool => $entry->email !== null
                || ($texts && SemaphoreSms::recipient($entry->phone) !== null))
            ->each(function (DrivePreRegistration $entry) use ($drive, $notice, $failure, &$sent): void {
                try {
                    Notification::route('mail', $entry->email)
                        ->route('sms', $entry->phone)
                        ->notify($notice($entry));
                    $sent++;
                } catch (Throwable $exception) {
                    Log::warning($failure, [
                        'mobile_event_id' => $drive->id,
                        'pre_registration_id' => $entry->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });

        return $sent;
    }

    /**
     * Anonymise the entries never turned into a booking, once their drive is
     * more than the retention period past (project owner, 2026-11-11: about
     * 90 days). The rows stay, so the drive's figures do.
     *
     * @return int how many entries were anonymised
     */
    public function anonymiseExpired(): int
    {
        $days = (int) config('donation.pre_registration_retention_days');
        $heldBefore = OperationalDay::today()->subDays($days)->toDateString();
        $total = 0;

        foreach ($this->repository->drivesDueForAnonymising($heldBefore) as $drive) {
            $count = DB::transaction(fn (): int => $this->repository->anonymiseDrive($drive->id));
            $total += $count;

            $this->auditLogger->record(null, 'drive.pre_registrations_anonymised', $drive, [
                'facility_id' => $drive->facility_id,
                'rows' => $count,
            ]);
        }

        return $total;
    }

    /**
     * Lock an entry on one of the caller's drives, apply a change, save and
     * audit it.
     *
     * @param  callable(DrivePreRegistration): void  $apply
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function change(User $staff, int $driveId, int $entryId, string $action, callable $apply, string $message, array $context = []): array
    {
        $drive = $this->repository->findDriveForFacility($driveId, $this->requireFacility($staff)->id)
            ?? throw $this->refuse(404, 'drive_not_found', 'That drive was not found at your facility.');

        $entry = DB::transaction(function () use ($drive, $entryId, $apply): DrivePreRegistration {
            $entry = $this->repository->lockForDrive($entryId, $drive->id)
                ?? throw $this->refuse(404, 'pre_registration_not_found', 'That person was not found on this drive\'s list.');

            if ($entry->anonymised_at !== null) {
                throw $this->refuse(409, 'pre_registration_anonymised', 'This entry was anonymised and can no longer change.');
            }

            $apply($entry);
            $entry->save();

            return $entry;
        });

        $this->auditLogger->record($staff, $action, $entry, [
            'facility_id' => $drive->facility_id,
            'mobile_event_id' => $drive->id,
            ...$context,
        ]);

        return [
            'message' => $message,
            'data' => [
                'id' => $entry->id,
                'status' => $entry->status->value,
                'donor_id' => $entry->donor_id,
                'appointment_id' => $entry->appointment_id,
            ],
        ];
    }

    /**
     * The drive behind a link, locked, if it is taking registrations. No
     * limit: the link takes everyone (project owner, 2026-11-11).
     */
    private function lockOpenDrive(string $token): MobileEvent
    {
        $drive = $this->repository->lockPublishedByToken($token) ?? throw $this->linkNotFound();

        return match ($this->linkState($drive)) {
            'cancelled' => throw $this->refuse(409, 'drive_cancelled', 'This drive was cancelled.'),
            'completed' => throw $this->refuse(409, 'drive_completed', 'This drive has already taken place.'),
            'closed' => throw $this->refuse(409, 'registration_closed', 'Registration for this drive is closed.'),
            default => $drive,
        };
    }

    /**
     * open, closed, cancelled or completed, as the link page reads it.
     */
    private function linkState(MobileEvent $drive): string
    {
        return match (true) {
            $drive->isCancelled() => 'cancelled',
            $drive->hasPassed() => 'completed',
            $drive->registration_closed_at !== null => 'closed',
            default => 'open',
        };
    }

    /**
     * A person as stored: trimmed names, a lower-cased email and an E.164
     * mobile number, so duplicates compare like with like.
     *
     * @param  array<string, mixed>  $input
     * @return array{first_name: string, last_name: string, email: ?string, phone: ?string, birth_date: ?string, affiliation: ?string}
     */
    private function person(array $input): array
    {
        $affiliation = trim((string) ($input['affiliation'] ?? ''));

        return [
            'first_name' => trim((string) $input['first_name']),
            'last_name' => trim((string) $input['last_name']),
            'email' => $this->email($input['email'] ?? null),
            'phone' => $this->phone($input['phone'] ?? null),
            'birth_date' => $input['birth_date'] ?? null,
            'affiliation' => $affiliation === '' ? null : $affiliation,
        ];
    }

    private function email(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    private function phone(mixed $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : AccountIdentity::normalizePhilippinePhone($phone);
    }

    /**
     * Whether the person's email or mobile number is already on the list.
     *
     * @param  array{email: ?string, phone: ?string}  $person
     * @param  array{emails: array<string, true>, phones: array<string, true>}  $onList
     */
    private function isListed(array $person, array $onList): bool
    {
        return ($person['email'] !== null && isset($onList['emails'][$person['email']]))
            || ($person['phone'] !== null && isset($onList['phones'][$person['phone']]));
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

    private function linkNotFound(): HttpResponseException
    {
        return $this->refuse(404, 'drive_link_not_found', 'This registration link is not valid.');
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
