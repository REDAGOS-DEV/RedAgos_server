<?php

namespace App\Service;

use App\Enums\AppointmentStatus;
use App\Enums\BloodBagType;
use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Models\BloodUnit;
use App\Models\CorrectionRequest;
use App\Models\CounsellingReferral;
use App\Models\DeferralCode;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationClearance;
use App\Models\DonationComponent;
use App\Models\DonationImmunohematology;
use App\Models\DonationSerology;
use App\Models\DonationTestResult;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\PaperDonationSheet;
use App\Models\User;
use App\Notifications\DonationRecorded;
use App\Notifications\DonorDeferred;
use App\Repository\CollectionRepository;
use App\Repository\DonationRecordRepository;
use App\Repository\DonorDirectoryRepository;
use App\Repository\EligibilityRepository;
use App\Repository\PaperDonationSheetRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * A drive's paper records, typed in after the drive (project owner, 2026-10-17).
 *
 * A drive in a remote area has no connection: the physician and the
 * phlebotomist work on paper, and the sheets come back with the bags. Staff
 * open a sheet for the drive and day, then type each donor from it. Each row
 * becomes an ordinary donation dated on the drive's day: a drawn bag goes to
 * Testing like one from the counter, a deferral defers the donor, and the
 * donor's eligibility runs from the day they actually gave.
 *
 * What differs from the counter, and why:
 *  - One person types the whole record, so one ability (donations.record_paper)
 *    covers what the counter splits across three roles.
 *  - The physician and phlebotomist are the names on the paper, as written.
 *    `recorded_by` and `collected_by` remain the staff member who typed it.
 *  - The donor answered the paper DHQ, so every screening says so.
 *  - Any past day is allowed (owner's choice), so the after-draw effects are
 *    dated: only a questionnaire answered by the drive's day is used up, and
 *    the donor is emailed only if the sheet says to.
 */
class PaperDonationService
{
    public function __construct(
        private readonly PaperDonationSheetRepository $sheets,
        private readonly CollectionRepository $collectionRepository,
        private readonly CollectionService $collectionService,
        private readonly DonationRecordRepository $donationRecords,
        private readonly DonationRecordService $donationRecordService,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly DonorDirectoryService $donorDirectoryService,
        private readonly EligibilityRepository $eligibilityRepository,
        private readonly EligibilityRuleEvaluator $evaluator,
        private readonly DonorNotifier $donorNotifier,
        private readonly AuditLogger $auditLogger,
        private readonly DeferralCodeService $deferralCodes,
    ) {}

    /**
     * This centre's sheets, the most recent drive first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $staff, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        return $this->sheets
            ->paginate($facility->id, $perPage)
            ->through(fn (PaperDonationSheet $sheet): array => $this->formatSheet($sheet));
    }

    /**
     * What a new sheet is picked from: the centre's drives already held.
     *
     * @return array<string, mixed>
     */
    public function options(User $staff): array
    {
        $facility = $this->requireFacility($staff);

        return [
            'today' => OperationalDay::todayAsDate(),
            'drives' => $this->sheets->drivesHeld($facility->id)->map(fn (MobileEvent $event): array => [
                'id' => $event->id,
                'name' => $event->name,
                'location' => $event->location,
                'event_date' => $event->event_date->toDateString(),
                'start_time' => $event->start_time === null ? null : substr($event->start_time, 0, 5),
            ])->values()->all(),
        ];
    }

    /**
     * Open a sheet for a drive and day.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $event = isset($payload['mobile_event_id'])
            ? MobileEvent::query()->where('facility_id', $facility->id)->find((int) $payload['mobile_event_id'])
            : null;

        $sheet = $this->sheets->create([
            'facility_id' => $facility->id,
            'mobile_event_id' => $event?->id,
            // Kept on the sheet even for a planned drive, so the list still
            // says where a record came from if the drive is later removed.
            'drive_name' => $event?->name ?? $payload['drive_name'],
            'location' => $payload['location'] ?? $event?->location,
            'held_on' => $payload['held_on'],
            'starts_at' => $payload['starts_at'],
            'screened_by_name' => $payload['screened_by_name'] ?? null,
            'phlebotomist_name' => $payload['phlebotomist_name'] ?? null,
            'notify_donors' => (bool) ($payload['notify_donors'] ?? true),
            'notes' => $payload['notes'] ?? null,
            'created_by' => $staff->id,
        ]);

        $this->auditLogger->record($staff, 'paper.sheet_created', $sheet, [
            'facility_id' => $facility->id,
            'mobile_event_id' => $sheet->mobile_event_id,
            'held_on' => $sheet->held_on->toDateString(),
        ]);

        return [
            'message' => 'Sheet '.$sheet->code().' opened.',
            'data' => $this->formatSheet($this->sheets->find($sheet->id, $facility->id)),
        ];
    }

    /**
     * A sheet and every donor typed from it.
     *
     * @return array<string, mixed>
     */
    public function show(User $staff, int $sheetId): array
    {
        $facility = $this->requireFacility($staff);
        $sheet = $this->sheets->find($sheetId, $facility->id) ?? throw $this->sheetNotFound();

        $donations = $this->donationRecords->query($facility->id, ['paper_sheet_id' => $sheet->id])
            ->reorder()
            ->orderBy('id')
            ->get();

        $touched = $this->touchedIds($donations->pluck('id')->all());

        return [
            'data' => [
                ...$this->formatSheet($sheet),
                'rows' => $donations->map(
                    fn (Donation $donation): array => $this->formatRow($donation, $staff, in_array($donation->id, $touched, true))
                )->values()->all(),
            ],
        ];
    }

    /**
     * Remove a sheet opened by mistake. Only an empty one: a sheet with rows
     * is the record of where those donations came from.
     *
     * @return array<string, mixed>
     */
    public function delete(User $staff, int $sheetId): array
    {
        $facility = $this->requireFacility($staff);

        DB::transaction(function () use ($facility, $sheetId, $staff): void {
            $sheet = $this->sheets->lock($sheetId, $facility->id) ?? throw $this->sheetNotFound();

            if ($sheet->donations()->exists()) {
                throw $this->refuse(409, 'sheet_not_empty', 'This sheet has donors on it. Remove them first.');
            }

            $this->auditLogger->record($staff, 'paper.sheet_deleted', $sheet, [
                'facility_id' => $facility->id,
                'drive_name' => $sheet->drive_name,
                'held_on' => $sheet->held_on->toDateString(),
            ]);

            $sheet->delete();
        });

        return ['message' => 'Sheet removed.'];
    }

    /**
     * Register a donor named on the paper who is not in RedAgos yet.
     *
     * The counter's own registration (StoreWalkInDonorRequest and
     * DonorDirectoryService::registerWalkIn()), reached through the paper's
     * ability: whoever types a drive's records registers its new donors, not
     * only the desk that holds donors.manage.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerDonor(User $staff, array $payload): array
    {
        return $this->donorDirectoryService->registerWalkIn($staff, $payload, 'donor.registered_from_paper');
    }

    /**
     * Type one donor's paper record: the screening, and the bag if one was drawn.
     *
     * One transaction, so a record is either all there or not at all; a
     * half-typed donor is never left as a visit "in progress" that the counter
     * would then refuse a real visit over.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function record(User $staff, int $sheetId, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($payload['donor_uuid'])
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        $outcome = ScreeningOutcome::from($payload['outcome']);
        $drawn = $outcome->permitsCollection() && ! (bool) ($payload['draw_failed'] ?? false);
        $notToLab = $drawn && (bool) ($payload['not_to_lab'] ?? false);

        // The code the drive's physician wrote on the paper, from the
        // centre's list, or Other with the reason as written: the record
        // carries it as the counter's would.
        $deferral = $outcome->permitsCollection()
            ? null
            : $this->deferralCodes->chosen($facility, $payload);

        try {
            [$donation, $sheet] = DB::transaction(function () use ($staff, $facility, $sheetId, $donor, $payload, $outcome, $deferral, $drawn, $notToLab): array {
                $sheet = $this->sheets->lock($sheetId, $facility->id) ?? throw $this->sheetNotFound();

                if ($this->sheets->donorOnSheet($sheet->id, $donor->id)) {
                    throw $this->refuse(409, 'already_on_sheet', $donor->first_name.' '.$donor->last_name.' is already on this sheet.');
                }

                $at = $this->visitTime($sheet, $payload);

                // A second draw inside the interval is either a typing slip
                // (the wrong donor, the same row twice) or a real early draw
                // the paper shows. Staff decide which; the server only makes
                // sure someone looked.
                if ($drawn && ! (bool) ($payload['confirm_interval'] ?? false)) {
                    $near = $this->sheets->drawNear(
                        $donor->id,
                        OperationalDay::dateOf($at),
                        (int) config('donation.interval_days')
                    );

                    if ($near !== null) {
                        $nearDay = OperationalDay::dateOf($near->donation_date);

                        throw $this->refuse(
                            409,
                            'interval_conflict',
                            'This donor also gave blood on '.CarbonImmutable::parse($nearDay)->format('j M Y')
                                .', closer than '.config('donation.interval_days').' days to this drive.',
                            [
                                'donation_id' => $near->id,
                                'code' => 'DON-'.str_pad((string) $near->id, 6, '0', STR_PAD_LEFT),
                                'date' => $nearDay,
                            ]
                        );
                    }
                }

                // The donor's booking for this drive, if they had one: the
                // paper record is that visit, and closes it.
                $booking = $sheet->mobile_event_id === null
                    ? null
                    : $this->sheets->lockDriveBooking($donor->id, $facility->id, (int) $sheet->mobile_event_id);

                $donation = $this->collectionRepository->createDonation([
                    'donor_id' => $donor->id,
                    'facility_id' => $facility->id,
                    'appointment_id' => $booking?->id,
                    'paper_sheet_id' => $sheet->id,
                    'donation_date' => $at,
                    'status' => DonationStatus::Registered,
                ]);

                $this->collectionRepository->upsertScreening($donation->id, [
                    'facility_id' => $facility->id,
                    // Who typed it. The physician is the paper's name below.
                    'recorded_by' => $staff->id,
                    'screened_by_name' => $payload['screened_by_name'],
                    ...$this->collectionService->screeningAttributes($payload, $outcome, $deferral),
                    // A drive works from the printed DHQ: there is no onsite
                    // questionnaire to have answered.
                    'dhq_on_paper' => true,
                    'screened_at' => $at,
                ]);

                if (! $outcome->permitsCollection()) {
                    $donation->status = DonationStatus::Rejected;
                    // The code, as at the counter (CollectionService::recordScreening()).
                    $donation->rejection_reason = $deferral['code'] ?? DeferralCode::OTHER;
                } elseif (! $drawn) {
                    // Accepted, but no bag: the counter's "Collection
                    // unsuccessful", written as the paper reports it.
                    $donation->status = DonationStatus::Rejected;
                    $donation->rejection_reason = trim((string) $payload['draw_failed_reason']);
                } else {
                    $box = $payload['collection'];
                    $endedAt = $this->instant($box['ended_at']);

                    $this->collectionRepository->createCollection([
                        'donation_id' => $donation->id,
                        'facility_id' => $facility->id,
                        // Who typed it; the phlebotomist is the paper's name.
                        'collected_by' => $staff->id,
                        'phlebotomist_name' => $box['phlebotomist_name'],
                        'blood_bag_type' => BloodBagType::from($box['blood_bag_type']),
                        'donation_barcode' => $box['donation_barcode'],
                        'started_at' => $this->instant($box['started_at']),
                        'ended_at' => $endedAt,
                        'collection_datetime' => $endedAt,
                    ]);

                    $donation->status = DonationStatus::Collected;
                    $donation->volume_ml = (int) $box['volume_ml'];

                    // Drawn, but the bag is not coming: the draw still counts
                    // for the donor, and nothing waits in Testing for it.
                    if ($notToLab) {
                        $donation->status = DonationStatus::Rejected;
                        $donation->rejection_reason = trim((string) $payload['not_to_lab_reason']);
                    }

                    // The counter retires the donor's questionnaire and QR on
                    // a draw. Here only what this drive used up: a
                    // questionnaire answered by its day, and the QR it backed.
                    if ($this->eligibilityRepository->invalidateScreeningsAnsweredBy($donor->id, $at) > 0) {
                        $this->eligibilityRepository->revokeQrTokens($donor->id);
                    }
                }

                $donation->save();

                if ($booking !== null) {
                    $booking->status = AppointmentStatus::Completed;
                    $booking->save();
                }

                return [$donation, $sheet];
            });
        } catch (QueryException $exception) {
            // Outside the transaction, as at the counter: Postgres aborts the
            // whole transaction on a unique violation.
            $this->collectionService->rethrowBarcodeTaken($exception, 'collection.donation_barcode');

            throw $exception;
        }

        $this->auditLogger->record($staff, 'paper.donation_recorded', $donation, [
            'facility_id' => $facility->id,
            'paper_sheet_id' => $sheet->id,
            'outcome' => $outcome->value,
            'drawn' => $drawn,
            'not_to_lab' => $notToLab,
            'donation_barcode' => $drawn ? $payload['collection']['donation_barcode'] : null,
            'interval_confirmed' => (bool) ($payload['confirm_interval'] ?? false),
        ]);

        if ($sheet->notify_donors) {
            $this->notify($donation, $outcome, $drawn);
        }

        $row = $this->donationRecords->query($facility->id, ['paper_sheet_id' => $sheet->id])
            ->where('donations.id', $donation->id)
            ->first();

        return [
            'message' => match (true) {
                $outcome->isDeferral() => 'Recorded: '.$outcome->label().'.',
                ! $drawn => 'Recorded: the draw was not completed.',
                $notToLab => 'Recorded. The bag is marked as not reaching the laboratory.',
                default => 'Recorded. The bag is now with the Testing department.',
            },
            'data' => $this->formatRow($row, $staff, false),
        ];
    }

    /**
     * Take back a donor typed in error, before any department has acted on
     * the donation. By whoever typed it, or the Collection head.
     *
     * The rows are deleted rather than closed: a closed donation with a bag
     * still counts as a draw, and the point is that this donor did not give
     * blood that day. The audit row keeps what was removed and why.
     *
     * @return array<string, mixed>
     */
    public function remove(User $staff, int $sheetId, int $donationId, string $reason): array
    {
        $facility = $this->requireFacility($staff);

        DB::transaction(function () use ($staff, $facility, $sheetId, $donationId, $reason): void {
            $sheet = $this->sheets->lock($sheetId, $facility->id) ?? throw $this->sheetNotFound();

            $donation = $this->sheets->lockSheetDonation($sheet->id, $donationId, $facility->id)
                ?? throw $this->refuse(404, 'donation_not_found', 'That donor is not on this sheet.');

            $donation->load(['screening', 'collection']);

            if (! $this->mayRemove($staff, $donation)) {
                throw $this->refuse(403, 'not_your_entry', 'Only the person who typed this record, or the Collection head, may remove it.');
            }

            if ($this->touchedIds([$donation->id]) !== []) {
                throw $this->refuse(
                    409,
                    'entry_in_use',
                    'This donation has already been worked on by another department. Request a correction instead.'
                );
            }

            $context = [
                'facility_id' => $facility->id,
                'paper_sheet_id' => $sheet->id,
                'donation_id' => $donation->id,
                'donor_id' => $donation->donor_id,
                'held_on' => $sheet->held_on->toDateString(),
                'outcome' => $donation->screening?->outcome?->value,
                'donation_barcode' => $donation->collection?->donation_barcode,
                'reason' => trim($reason),
            ];

            // A booking the record closed is open again: whatever happened at
            // the drive, it was not this record.
            if ($donation->appointment_id !== null) {
                DonationAppointment::query()
                    ->where('id', $donation->appointment_id)
                    ->where('status', AppointmentStatus::Completed->value)
                    ->update(['status' => AppointmentStatus::Scheduled->value]);
            }

            $this->sheets->deleteDonation($donation);

            $this->auditLogger->record($staff, 'paper.donation_removed', $sheet, $context);
        });

        return ['message' => 'Record removed from the sheet.'];
    }

    /**
     * When the donor's visit is dated: the screening time the paper gives,
     * otherwise the drive's opening on its day.
     *
     * @param  array<string, mixed>  $payload
     */
    private function visitTime(PaperDonationSheet $sheet, array $payload): CarbonImmutable
    {
        if (filled($payload['screened_at'] ?? null)) {
            return $this->instant($payload['screened_at']);
        }

        return OperationalDay::moment($sheet->held_on->toDateString(), $sheet->startTime());
    }

    /**
     * A time the client sent, as an instant in the application's timezone,
     * which is how every other donation time is stored.
     */
    private function instant(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * The donor hears what a counter visit would have told them.
     */
    private function notify(Donation $donation, ScreeningOutcome $outcome, bool $drawn): void
    {
        $donation->loadMissing('donorProfile.donor');

        if ($outcome->isDeferral()) {
            // The code alone; "Other" has none, and the donor is told neither.
            $this->donorNotifier->send(
                $donation,
                fn (User $donor): DonorDeferred => new DonorDeferred($donation, $donation->screening()->value('deferral_code'), $outcome),
                'deferral notice'
            );

            return;
        }

        if ($drawn) {
            $this->donorNotifier->send(
                $donation,
                fn (User $donor): DonationRecorded => new DonationRecorded(
                    $donation,
                    $this->evaluator->nextEligibleDate($donation->donation_date)
                ),
                'donation receipt'
            );
        }
    }

    /**
     * Whoever typed the record, or the Collection head (the department's
     * correction approver), holding the paper ability and the delete privilege.
     */
    private function mayRemove(User $viewer, Donation $donation): bool
    {
        if (! $viewer->can('donations.record_paper') || ! $viewer->can('donations.close')) {
            return false;
        }

        return (int) $donation->screening?->recorded_by === (int) $viewer->id
            || $viewer->can('corrections.approve');
    }

    /**
     * The donations among these that any department has acted on since they
     * were typed: a test section or result, components, a bag booked in, a
     * clearance (revoked or not), a referral, or a correction request.
     *
     * A handful of queries for the whole sheet, not a handful per row.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function touchedIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $touched = [];

        foreach ([
            DonationImmunohematology::class,
            DonationSerology::class,
            DonationTestResult::class,
            DonationComponent::class,
            BloodUnit::class,
            DonationClearance::class,
            CounsellingReferral::class,
            CorrectionRequest::class,
        ] as $model) {
            $touched = [
                ...$touched,
                ...$model::query()->whereIn('donation_id', $ids)->pluck('donation_id')->map(fn ($id): int => (int) $id)->all(),
            ];
        }

        return array_values(array_unique($touched));
    }

    /**
     * A sheet row: the Donations list's own row, plus whether this viewer may
     * take it back.
     *
     * @return array<string, mixed>
     */
    private function formatRow(Donation $donation, User $viewer, bool $touched): array
    {
        $row = $this->donationRecordService->format($donation, $viewer);

        return [
            ...$row,
            'collection' => $row['collection'] === null ? null : [
                ...$row['collection'],
                'started_at' => $donation->collection?->started_at?->toISOString(),
                'ended_at' => $donation->collection?->ended_at?->toISOString(),
            ],
            'can' => [
                ...$row['can'],
                'remove' => ! $touched && $this->mayRemove($viewer, $donation),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSheet(PaperDonationSheet $sheet): array
    {
        $rows = (int) ($sheet->rows_count ?? 0);

        return [
            'id' => $sheet->id,
            'code' => $sheet->code(),
            'drive' => [
                'id' => $sheet->mobile_event_id,
                'name' => $sheet->drive_name,
                'location' => $sheet->location,
                'in_redagos' => $sheet->mobile_event_id !== null,
            ],
            'held_on' => $sheet->held_on->toDateString(),
            'starts_at' => $sheet->startTime(),
            'screened_by_name' => $sheet->screened_by_name,
            'phlebotomist_name' => $sheet->phlebotomist_name,
            'notify_donors' => (bool) $sheet->notify_donors,
            'notes' => $sheet->notes,
            'created_by' => $sheet->creator ? $sheet->creator->signatureName() : null,
            'created_at' => $sheet->created_at?->toISOString(),
            'counts' => [
                'rows' => $rows,
                'drawn' => (int) ($sheet->drawn_count ?? 0),
                'deferred' => (int) ($sheet->deferred_count ?? 0),
            ],
            'can' => [
                'delete' => $rows === 0,
            ],
        ];
    }

    private function sheetNotFound(): HttpResponseException
    {
        return $this->refuse(404, 'sheet_not_found', 'That sheet was not found at your centre.');
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function refuse(int $status, string $code, string $message, ?array $data = null): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
            ...($data === null ? [] : ['data' => $data]),
        ], $status));
    }
}
