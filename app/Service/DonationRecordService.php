<?php

namespace App\Service;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use App\Repository\DonationRecordRepository;
use App\Support\DonorBlinding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Donations list (project owner, 2026-10-17), built like Donor
 * Management: every visit opened at this centre's counter, where it got to,
 * and a way to find and close a visit left open.
 *
 * It exists because a visit opened and never finished kept the counter
 * refusing that donor ("already has a donation in progress") while nothing on
 * any list showed it. Recording a visit stays the counter's work; this lists,
 * exports and closes.
 *
 * What each viewer is told follows the counter's own rules
 * (CollectionService::formatDonation): the donor's name only to a holder of
 * donors.view_identity, a deferral's reason and a laboratory rejection only to
 * the clinical record's holder (donors.view_clinical).
 */
class DonationRecordService
{
    public function __construct(
        private readonly DonationRecordRepository $donations,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        return $this->donations
            ->paginate($facility->id, $filters, $perPage)
            ->through(fn (Donation $donation): array => $this->format($donation, $staff));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function csv(User $staff, array $filters): StreamedResponse
    {
        $facility = $this->requireFacility($staff);
        $query = $this->donations->query($facility->id, $filters);

        $this->auditLogger->record($staff, 'donations.exported', $facility, [
            'rows' => (clone $query)->count(),
            'filters' => array_intersect_key($filters, array_flip(['search', 'status', 'blood_type_id', 'from', 'to'])),
        ]);

        return response()->streamDownload(function () use ($query, $staff): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['DonationID', 'Donor', 'DonorID', 'BloodGroup', 'Date', 'Time', 'Status', 'Appointment', 'Drive', 'Bag', 'VolumeML', 'Screening', 'CollectedBy', 'Source'], escape: '');

            $query->chunk(300, function ($rows) use ($out, $staff): void {
                foreach ($rows as $donation) {
                    $row = $this->format($donation, $staff);

                    fputcsv($out, array_map(fn ($v): string => $this->cell($v), [
                        $row['code'],
                        $row['donor']['full_name'] ?? '',
                        $row['donor']['donor_code'] ?? '',
                        $row['donor']['blood_type'] ?? '',
                        $row['date'],
                        $row['time'],
                        $row['status_label'],
                        $row['appointment']['code'] ?? 'Walk-in',
                        $row['drive'] ?? '',
                        $row['bag']['barcode'] ?? '',
                        $row['volume_ml'] ?? '',
                        $row['screening']['outcome_label'] ?? '',
                        $row['collection']['collected_by'] ?? '',
                        $row['paper_sheet'] === null ? 'Counter' : 'Paper '.$row['paper_sheet']['code'],
                    ]), escape: '');
                }
            });

            fclose($out);
        }, 'donations-'.Str::slug($facility->name).'-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * One row. `open` marks a visit not yet drawn — the one the counter
     * refuses a second visit over — and `can.close` whether this viewer may
     * close it from the list.
     *
     * Public for a drive's paper sheet (PaperDonationService), whose rows are
     * these same rows and must follow the same rules about who is told what.
     *
     * @return array<string, mixed>
     */
    public function format(Donation $donation, User $viewer): array
    {
        // Read as recorded: donation_date is wall clock in the application's
        // timezone, as the counter writes it.
        $at = CarbonImmutable::instance($donation->donation_date);
        $status = $donation->status;
        $clinical = $viewer->can('donors.view_clinical');
        $screening = $donation->screening;
        $deferredAtScreening = $screening?->outcome?->isDeferral() ?? false;
        $open = in_array($status, [DonationStatus::Registered, DonationStatus::Screening], true);

        // A closed visit says why only as far as this viewer may know: a
        // screening deferral is on the counter's own record; a laboratory
        // rejection is the clinical record's.
        $label = match (true) {
            $status === DonationStatus::Rejected && $deferredAtScreening => 'Deferred',
            $status === DonationStatus::Rejected && $clinical && $donation->counsellingReferral !== null => 'Rejected after testing',
            $status === DonationStatus::Rejected => 'Closed',
            default => $status->label(),
        };

        $appointment = $donation->appointment;
        $sheet = $donation->paperSheet;

        return [
            'id' => $donation->id,
            'code' => 'DON-'.str_pad((string) $donation->id, 6, '0', STR_PAD_LEFT),
            'donation_date' => $donation->donation_date?->toISOString(),
            'date' => $at->toDateString(),
            'time' => $at->format('H:i'),
            'status' => $status->value,
            'status_label' => $label,
            'open' => $open,
            'donor' => DonorBlinding::block(
                $donation->donorProfile?->donor,
                $donation->donorProfile?->bloodType?->code,
                $viewer
            ),
            'appointment' => $appointment === null ? null : [
                'id' => $appointment->id,
                'code' => 'APT-'.str_pad((string) $appointment->id, 6, '0', STR_PAD_LEFT),
            ],
            // A walk-in at a drive has no booking; its paper sheet names the drive.
            'drive' => $appointment?->mobileEvent?->name ?? $sheet?->drive_name,
            // Typed from a drive's paper record, or recorded at the counter.
            'source' => $sheet === null ? 'counter' : 'paper',
            'paper_sheet' => $sheet === null ? null : [
                'id' => $sheet->id,
                'code' => $sheet->code(),
                // Who typed it: on a paper record the screening's recorder is
                // the encoder, and the names below are the paper's.
                'entered_by' => $screening?->recorder
                    ? trim($screening->recorder->first_name.' '.$screening->recorder->last_name)
                    : null,
            ],
            'volume_ml' => $donation->volume_ml,
            'bag' => $donation->collection === null ? null : [
                'barcode' => $donation->collection->donation_barcode,
                'type' => $donation->collection->blood_bag_type?->label(),
            ],
            'screening' => $screening === null ? null : [
                'outcome_label' => $screening->outcome?->label(),
                'dhq_on_paper' => (bool) $screening->dhq_on_paper,
                'screened_at' => $screening->screened_at?->toISOString(),
                'recorded_by' => $screening->screenerName(),
                'deferral_reason' => $clinical ? $screening->deferral_reason : null,
            ],
            'collection' => $donation->collection === null ? null : [
                'collected_at' => $donation->collection->collection_datetime?->toISOString(),
                'collected_by' => $donation->collection->phlebotomistName(),
            ],
            'rejection_reason' => $clinical ? $donation->rejection_reason : null,
            'can' => [
                // The same act as the counter's Cancel visit, through its route.
                'close' => $open && $viewer->can('donations.close'),
            ],
        ];
    }

    private function cell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));
    }
}
