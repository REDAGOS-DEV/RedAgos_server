<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Enums\EligibilityStatus;
use App\Enums\PreRegistrationSource;
use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DrivePreRegistration;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Repository\DonorDeferralRepository;
use App\Repository\DrivePreRegistrationRepository;
use App\Support\OperationalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * One drive's roster, and the figures that reconcile with it (drive
 * pre-registration, phases 4 and 6; project owner, 2026-11-12).
 *
 * Everyone the drive knows of, one row each:
 *  - a person on the registration link's list, not yet booked;
 *  - a booking for the drive: made in the portal, by the desk, or at the
 *    drive for a walk-in. A list entry tied to a booking is shown, and
 *    counted, as that booking;
 *  - a donation typed in from the drive's paper sheet with no booking.
 *
 * Planning and operations are kept apart. Pre-registered donors is the one
 * planning figure: bookings made ahead that still stand, plus the list not yet
 * booked. Booked, checked in and no-show come from the appointment. Deferred
 * and collected come only from the donation's own screening and collection
 * records: a booking or a list entry never says anyone gave.
 *
 * The self-check reads the donor's own portal questionnaire, as a status only:
 * Cleared, Needs follow-up or Not completed. A person without a portal
 * account is Not completed, and is screened in person at the drive.
 */
class DriveRosterService
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'pre_registered' => 'Pre-registered',
        'withdrawn' => 'Withdrawn',
        'booked' => 'Booked',
        'checked_in' => 'Checked in',
        'no_show' => 'No-show',
        'cancelled' => 'Cancelled',
        'deferred' => 'Deferred',
        'collected' => 'Collected',
    ];

    /** @var array<string, string> */
    private const SOURCE_LABELS = [
        'self' => 'Self',
        'partner' => 'Partner',
        'portal' => 'Portal booking',
        'desk' => 'Desk booking',
        'walk_in' => 'Walk-in',
        'paper' => 'Paper record',
    ];

    public function __construct(
        private readonly DrivePreRegistrationRepository $preRegistrations,
        private readonly DonorDeferralRepository $deferrals
    ) {}

    /**
     * The roster and its figures for one of the caller's drives.
     *
     * @return array<string, mixed>
     */
    public function roster(User $staff, int $driveId): array
    {
        $drive = $this->preRegistrations->findDriveForFacility($driveId, $this->requireFacility($staff)->id)
            ?? throw $this->refuse(404, 'drive_not_found', 'That drive was not found at your facility.');

        $entries = $this->preRegistrations->forDrive($drive->id);
        $bookings = DonationAppointment::query()
            ->with(['donorProfile.donor:id,uuid,first_name,last_name,email,phone'])
            ->where('event_id', $drive->id)
            ->orderBy('appointment_datetime')
            ->orderBy('id')
            ->get();
        $visits = $this->visitsFor($drive, $bookings);
        $paper = $visits->filter(fn (Donation $visit): bool => $visit->appointment_id === null);

        $donorIds = collect([
            ...$entries->pluck('donor_id')->all(),
            ...$bookings->pluck('donor_id')->all(),
            ...$paper->pluck('donor_id')->all(),
        ])->filter()->unique()->values()->all();
        $selfCheck = $this->selfCheckStatuses($donorIds);
        $possible = $this->possibleDonors($entries);
        $entryByBooking = $entries->filter(fn (DrivePreRegistration $entry): bool => $entry->appointment_id !== null)->keyBy('appointment_id');
        $driveDay = ! $drive->isCancelled() && $drive->event_date->toDateString() === OperationalDay::todayAsDate();

        $rows = collect();

        foreach ($entries as $entry) {
            if ($entry->appointment_id === null || ! $bookings->contains('id', $entry->appointment_id)) {
                $rows->push($this->entryRow($entry, $selfCheck, $possible[$entry->id] ?? null, $driveDay));
            }
        }

        foreach ($bookings as $booking) {
            // The latest visit, as DonationAppointment::donation() reads it.
            $visit = $visits->where('appointment_id', $booking->id)->last();
            $rows->push($this->bookingRow($booking, $visit, $entryByBooking->get($booking->id), $selfCheck, $driveDay));
        }

        foreach ($paper as $visit) {
            $rows->push($this->paperRow($visit, $selfCheck));
        }

        return [
            'drive' => [
                'id' => $drive->id,
                'name' => $drive->name,
                'location' => $drive->location,
                'event_date' => $drive->event_date->toDateString(),
                'start_time' => $drive->start_time,
                'end_time' => $drive->end_time,
                'status' => $drive->status(),
                'registration_open' => $drive->acceptsRegistrations(),
                // The link only answers once the drive is published.
                'registration_token' => $drive->isPublished() ? $drive->registration_token : null,
                // Check-in and walk-ins happen on the drive's own day.
                'is_drive_day' => $driveDay,
            ],
            'summary' => $this->summary($drive, $rows),
            'data' => $rows->values()->all(),
        ];
    }

    /**
     * The figures, from the rows themselves so the two always agree.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summary(MobileEvent $drive, Collection $rows): array
    {
        $count = fn (callable $test): int => $rows->filter($test)->count();
        $attended = ['checked_in', 'deferred', 'collected'];

        return [
            // Planning: who said ahead of the drive that they are coming.
            'pre_registered_donors' => $drive->preRegisteredCount(),
            'listed' => $count(fn (array $row): bool => $row['kind'] === 'listed' && $row['status'] === 'pre_registered'),
            'from_partners' => $count(fn (array $row): bool => $row['source'] === 'partner' && in_array($row['status'], ['pre_registered', 'booked', ...$attended, 'no_show'], true)),
            'booked' => $count(fn (array $row): bool => $row['kind'] === 'booking' && ! $row['walk_in'] && $row['status'] !== 'cancelled'),
            'withdrawn' => $count(fn (array $row): bool => $row['status'] === 'withdrawn'),
            // On the day: from the appointments and the donations themselves.
            'walk_ins' => $count(fn (array $row): bool => ($row['kind'] === 'booking' && $row['walk_in'] && $row['status'] !== 'cancelled') || $row['kind'] === 'paper'),
            'checked_in' => $count(fn (array $row): bool => in_array($row['status'], $attended, true)),
            'no_show' => $count(fn (array $row): bool => $row['status'] === 'no_show'),
            'deferred' => $count(fn (array $row): bool => $row['status'] === 'deferred'),
            'collected' => $count(fn (array $row): bool => $row['status'] === 'collected'),
            'cancelled' => $count(fn (array $row): bool => $row['status'] === 'cancelled'),
        ];
    }

    /**
     * The drive's donations: through a booking for it, or typed in from one
     * of its paper sheets. With what the stage needs.
     *
     * @param  Collection<int, DonationAppointment>  $bookings
     * @return Collection<int, Donation>
     */
    private function visitsFor(MobileEvent $drive, Collection $bookings): Collection
    {
        return Donation::query()
            ->with(['screening:id,donation_id,outcome', 'donorProfile.donor:id,uuid,first_name,last_name,email,phone'])
            ->withExists('collection')
            ->where(fn (Builder $query) => $query
                ->whereIn('appointment_id', $bookings->pluck('id')->all())
                ->orWhereHas('paperSheet', fn (Builder $sheet) => $sheet->where('mobile_event_id', $drive->id)))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, string>  $selfCheck
     * @return array<string, mixed>
     */
    private function entryRow(DrivePreRegistration $entry, array $selfCheck, ?User $possible, bool $driveDay): array
    {
        $live = $entry->anonymised_at === null;
        $onList = $entry->status->isOnList();
        $partner = $entry->source === PreRegistrationSource::Partner;

        return [
            ...$this->row('listed', 'entry-'.$entry->id),
            'entry_id' => $entry->id,
            'first_name' => $entry->first_name,
            'last_name' => $entry->last_name,
            'email' => $entry->email,
            'phone' => $entry->phone,
            'birth_date' => $entry->birth_date?->toDateString(),
            'affiliation' => $entry->affiliation,
            'source' => $entry->source->value,
            'source_label' => $partner ? ($entry->partnerSubmission?->organisation_name ?? 'Partner') : 'Self',
            'organisation' => $entry->partnerSubmission?->organisation_name,
            ...$this->status($entry->status->value),
            'self_check' => $entry->donor_id !== null ? ($selfCheck[$entry->donor_id] ?? 'not_completed') : 'not_completed',
            'donor' => $entry->donor ? $this->donorRef($entry->donor) : null,
            'possible_donor' => $possible ? $this->donorRef($possible) : null,
            'anonymised' => ! $live,
            'can' => [
                'withdraw' => $live && $onList,
                'match' => $live && $onList && $entry->donor_id === null && $possible !== null,
                'unmatch' => $live && $entry->donor_id !== null,
                'register' => $live && $onList && $entry->donor_id === null,
                'check_in' => $live && $onList && $entry->donor_id !== null && $driveDay,
                'open_counter' => false,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $selfCheck
     * @return array<string, mixed>
     */
    private function bookingRow(DonationAppointment $booking, ?Donation $visit, ?DrivePreRegistration $entry, array $selfCheck, bool $driveDay): array
    {
        $donor = $booking->donorProfile?->donor;
        $source = match (true) {
            $entry !== null => $entry->source->value,
            $booking->walk_in => 'walk_in',
            $booking->booked_by === null => 'portal',
            default => 'desk',
        };
        $status = $this->bookingStatus($booking, $visit);

        return [
            ...$this->row('booking', 'booking-'.$booking->id),
            'entry_id' => $entry?->id,
            'appointment_id' => $booking->id,
            'donation_id' => $visit?->id,
            'walk_in' => (bool) $booking->walk_in,
            'first_name' => $donor?->first_name,
            'last_name' => $donor?->last_name,
            'email' => $donor?->email,
            'phone' => $donor?->phone,
            'affiliation' => $entry?->affiliation,
            'source' => $source,
            'source_label' => $source === 'partner'
                ? ($entry?->partnerSubmission?->organisation_name ?? 'Partner')
                : self::SOURCE_LABELS[$source],
            'organisation' => $entry?->partnerSubmission?->organisation_name,
            ...$this->status($status),
            'self_check' => $selfCheck[$booking->donor_id] ?? 'not_completed',
            'donor' => $donor ? $this->donorRef($donor) : null,
            'can' => [
                ...$this->row('booking', '')['can'],
                // The counter checks a booked donor in, and opens the visit.
                'open_counter' => $driveDay && $donor !== null && in_array($status, ['booked', 'checked_in'], true) && $visit === null,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $selfCheck
     * @return array<string, mixed>
     */
    private function paperRow(Donation $visit, array $selfCheck): array
    {
        $donor = $visit->donorProfile?->donor;

        return [
            ...$this->row('paper', 'paper-'.$visit->id),
            'donation_id' => $visit->id,
            'walk_in' => true,
            'first_name' => $donor?->first_name,
            'last_name' => $donor?->last_name,
            'email' => $donor?->email,
            'phone' => $donor?->phone,
            'source' => 'paper',
            'source_label' => self::SOURCE_LABELS['paper'],
            ...$this->status($this->visitStatus($visit)),
            'self_check' => $selfCheck[$visit->donor_id] ?? 'not_completed',
            'donor' => $donor ? $this->donorRef($donor) : null,
        ];
    }

    /**
     * The keys every row carries, empty.
     *
     * @return array<string, mixed>
     */
    private function row(string $kind, string $key): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'entry_id' => null,
            'appointment_id' => null,
            'donation_id' => null,
            'walk_in' => false,
            'birth_date' => null,
            'affiliation' => null,
            'organisation' => null,
            'possible_donor' => null,
            'anonymised' => false,
            'can' => [
                'withdraw' => false,
                'match' => false,
                'unmatch' => false,
                'register' => false,
                'check_in' => false,
                'open_counter' => false,
            ],
        ];
    }

    /**
     * @return array{status: string, status_label: string}
     */
    private function status(string $status): array
    {
        return ['status' => $status, 'status_label' => self::STATUS_LABELS[$status]];
    }

    /**
     * Where a booking has got to. Deferred and collected only from the
     * donation's own records; a booking marked complete without a donation
     * says the donor came, nothing more.
     */
    private function bookingStatus(DonationAppointment $booking, ?Donation $visit): string
    {
        return match (true) {
            $booking->status === AppointmentStatus::Cancelled => 'cancelled',
            $booking->status === AppointmentStatus::NoShow => 'no_show',
            $visit !== null => $this->visitStatus($visit),
            in_array($booking->status, [AppointmentStatus::Confirmed, AppointmentStatus::Completed], true) => 'checked_in',
            default => 'booked',
        };
    }

    /**
     * deferred at screening, collected (a collection was recorded), or checked
     * in: the visit is under way, or ended without a bag.
     */
    private function visitStatus(Donation $visit): string
    {
        return match (true) {
            $visit->screening?->outcome instanceof ScreeningOutcome && $visit->screening->outcome->isDeferral() => 'deferred',
            (bool) $visit->collection_exists => 'collected',
            default => 'checked_in',
        };
    }

    /**
     * The self-check as a status only, never an answer: cleared when the
     * donor's current portal questionnaire passed; needs follow-up when it
     * flagged something or the donor holds a deferral; otherwise not
     * completed.
     *
     * @param  array<int, int>  $donorIds
     * @return array<int, string> keyed by donor id
     */
    private function selfCheckStatuses(array $donorIds): array
    {
        if ($donorIds === []) {
            return [];
        }

        $deferred = $this->deferrals->deferralsFor($donorIds);
        $current = EligibilityScreening::query()
            ->whereIn('donor_id', $donorIds)
            ->currentlyValid()
            ->orderBy('screened_at')
            ->orderBy('id')
            ->get(['id', 'donor_id', 'result'])
            ->keyBy('donor_id');

        $statuses = [];

        foreach ($donorIds as $id) {
            $statuses[$id] = match (true) {
                isset($deferred[$id]) => 'follow_up',
                ! $current->has($id) => 'not_completed',
                $current[$id]->result === EligibilityStatus::Eligible => 'cleared',
                default => 'follow_up',
            };
        }

        return $statuses;
    }

    /**
     * The existing donor each unmatched entry's email or mobile number belongs
     * to, if any: a suggestion staff confirm, never a link of its own.
     *
     * @param  Collection<int, DrivePreRegistration>  $entries
     * @return array<int, User> keyed by entry id
     */
    private function possibleDonors(Collection $entries): array
    {
        $open = $entries->filter(fn (DrivePreRegistration $entry): bool => $entry->donor_id === null
            && $entry->anonymised_at === null
            && $entry->status->isOnList());

        $donors = $this->preRegistrations->donorsByContact(
            $open->pluck('email')->filter()->unique()->values()->all(),
            $open->pluck('phone')->filter()->unique()->values()->all()
        );

        $byEmail = $donors->filter(fn (User $donor): bool => filled($donor->email))->keyBy(fn (User $donor): string => mb_strtolower($donor->email));
        // A household may share one mobile number (2026-11-12), so a number
        // suggests a donor only when it is theirs alone.
        $byPhone = $donors->filter(fn (User $donor): bool => filled($donor->phone))
            ->groupBy('phone')
            ->filter(fn (Collection $holders): bool => $holders->count() === 1)
            ->map(fn (Collection $holders): User => $holders->first());

        $matches = [];

        foreach ($open as $entry) {
            $donor = ($entry->email !== null ? $byEmail->get($entry->email) : null)
                ?? ($entry->phone !== null ? $byPhone->get($entry->phone) : null);

            if ($donor !== null) {
                $matches[$entry->id] = $donor;
            }
        }

        return $matches;
    }

    /**
     * @return array{uuid: string, code: string, name: string}
     */
    private function donorRef(User $donor): array
    {
        return [
            'uuid' => (string) $donor->uuid,
            'code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            'name' => trim($donor->first_name.' '.$donor->last_name),
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
