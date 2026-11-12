<?php

namespace App\Repository;

use App\Models\DonationAppointment;
use App\Models\DrivePartnerSubmission;
use App\Models\DrivePreRegistration;
use App\Models\MobileEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DrivePreRegistrationRepository
{
    /**
     * The published drive a registration link opens, in any later state:
     * the page says when it was cancelled, closed or has been held. A draft
     * answers to nothing.
     */
    public function findPublishedByToken(string $token): ?MobileEvent
    {
        return MobileEvent::query()
            ->with('facility')
            ->where('registration_token', $token)
            ->whereNotNull('published_at')
            ->first();
    }

    /**
     * The same drive, locked for the rest of the transaction. The lock
     * bookings take (AppointmentRepository::lockDrive()), so a registration
     * never lands after the drive is cancelled or closed, and two copies of
     * one person cannot both pass the duplicate check.
     */
    public function lockPublishedByToken(string $token): ?MobileEvent
    {
        return MobileEvent::query()
            ->with('facility')
            ->where('registration_token', $token)
            ->whereNotNull('published_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * One of the facility's drives, in any state, with its pre-registered
     * donors count loaded.
     */
    public function findDriveForFacility(int $driveId, int $facilityId): ?MobileEvent
    {
        return MobileEvent::query()
            ->withPreRegisteredCounts()
            ->where('facility_id', $facilityId)
            ->whereKey($driveId)
            ->first();
    }

    /**
     * Tie a drive's list entries to the booking that now stands for the person,
     * so they are counted once, by the booking. The entries staff matched to
     * this donor, and, when the donor's email is verified, those carrying it.
     *
     * @return int how many entries were tied
     */
    public function tieToBooking(int $driveId, int $donorId, ?string $verifiedEmail, int $appointmentId): int
    {
        $email = $verifiedEmail !== null ? mb_strtolower(trim($verifiedEmail)) : null;

        return DrivePreRegistration::query()
            ->where('mobile_event_id', $driveId)
            ->onList()
            ->whereNull('appointment_id')
            ->whereNull('anonymised_at')
            ->where(fn (Builder $query) => $query
                ->where('donor_id', $donorId)
                ->when($email !== null && $email !== '', fn (Builder $q) => $q->orWhere(fn (Builder $byEmail) => $byEmail
                    ->whereNull('donor_id')
                    ->where('email', $email))))
            ->update([
                'appointment_id' => $appointmentId,
                'donor_id' => $donorId,
                'updated_at' => now(),
            ]);
    }

    /**
     * The booking made ahead of this drive that stands for a donor, if any.
     */
    public function standingBooking(int $driveId, int $donorId): ?DonationAppointment
    {
        return DonationAppointment::query()
            ->where('event_id', $driveId)
            ->where('donor_id', $donorId)
            ->preRegistered()
            ->latest('id')
            ->first();
    }

    /**
     * The emails and phone numbers already on a drive's list, as stored
     * (emails lower-cased, phones in E.164).
     *
     * @return array{emails: array<string, true>, phones: array<string, true>}
     */
    public function contactsOnList(int $driveId): array
    {
        $rows = DrivePreRegistration::query()
            ->where('mobile_event_id', $driveId)
            ->onList()
            ->get(['email', 'phone']);

        return [
            'emails' => $rows->pluck('email')->filter()->mapWithKeys(fn (string $email): array => [$email => true])->all(),
            'phones' => $rows->pluck('phone')->filter()->mapWithKeys(fn (string $phone): array => [$phone => true])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): DrivePreRegistration
    {
        return DrivePreRegistration::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createSubmission(array $attributes): DrivePartnerSubmission
    {
        return DrivePartnerSubmission::query()->create($attributes);
    }

    /**
     * Everyone ever put on a drive's list, newest first, with what the
     * staff list shows beside each.
     *
     * @return Collection<int, DrivePreRegistration>
     */
    public function forDrive(int $driveId): Collection
    {
        return DrivePreRegistration::query()
            ->with(['partnerSubmission:id,organisation_name', 'donor:id,uuid,first_name,last_name'])
            ->where('mobile_event_id', $driveId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One entry on one drive's list, locked for the rest of the transaction.
     */
    public function lockForDrive(int $id, int $driveId): ?DrivePreRegistration
    {
        return DrivePreRegistration::query()
            ->where('mobile_event_id', $driveId)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Donor accounts holding any of these emails or phone numbers: the
     * possible matches staff confirm or ignore. Exact matches only.
     *
     * @param  array<int, string>  $emails  lower-cased
     * @param  array<int, string>  $phones  E.164
     * @return Collection<int, User>
     */
    public function donorsByContact(array $emails, array $phones): Collection
    {
        if ($emails === [] && $phones === []) {
            return collect();
        }

        return User::query()
            ->whereHas('donorProfile')
            ->where(function (Builder $query) use ($emails, $phones): void {
                $query->when($emails !== [], fn (Builder $q) => $q->whereIn(DB::raw('LOWER(email)'), $emails))
                    ->when($phones !== [], fn (Builder $q) => $q->orWhereIn('phone', $phones));
            })
            ->get(['id', 'uuid', 'first_name', 'last_name', 'email', 'phone']);
    }

    /**
     * A donor account by its UUID.
     */
    public function findDonor(string $uuid): ?User
    {
        return User::query()->whereHas('donorProfile')->where('uuid', $uuid)->first();
    }

    /**
     * Drives held more than the retention period ago that still have
     * unconverted entries, or partner contacts, carrying personal data.
     *
     * @return Collection<int, MobileEvent>
     */
    public function drivesDueForAnonymising(string $heldBefore): Collection
    {
        return MobileEvent::query()
            ->withTrashed()
            ->whereDate('event_date', '<', $heldBefore)
            ->where(fn (Builder $drive) => $drive
                ->whereHas('preRegistrations', fn (Builder $query) => $query->whereNull('anonymised_at')->whereNull('appointment_id'))
                ->orWhereHas('partnerSubmissions', fn (Builder $query) => $query->whereNull('anonymised_at')))
            ->get(['id', 'facility_id', 'event_date']);
    }

    /**
     * Strip the personal data from a drive's unconverted entries and from its
     * partner batches. The rows stay, so the drive's counts do.
     */
    public function anonymiseDrive(int $driveId): int
    {
        $now = now();

        $count = DrivePreRegistration::query()
            ->where('mobile_event_id', $driveId)
            ->whereNull('anonymised_at')
            ->whereNull('appointment_id')
            ->update([
                'first_name' => null,
                'last_name' => null,
                'email' => null,
                'phone' => null,
                'birth_date' => null,
                'affiliation' => null,
                'donor_id' => null,
                'anonymised_at' => $now,
                'updated_at' => $now,
            ]);

        DrivePartnerSubmission::query()
            ->where('mobile_event_id', $driveId)
            ->whereNull('anonymised_at')
            ->update([
                'contact_name' => null,
                'contact_phone' => null,
                'contact_email' => null,
                'anonymised_at' => $now,
                'updated_at' => $now,
            ]);

        return $count;
    }
}
