<?php

namespace App\Service;

use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Enums\MarkerResult;
use App\Enums\SerologyMarker;
use App\Models\CorrectionRequest;
use App\Models\DonationSerology;
use App\Models\Facility;
use App\Models\User;
use App\Repository\SerologyResultRepository;
use App\Support\DonorBlinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TTI Testing's results list (project owner, 2026-10-18): every serology
 * panel recorded at this centre, marker by marker, with a way to correct one.
 *
 * Recording stays on the Testing page's queue. This lists, exports, and says
 * of each panel whether it can still be corrected — the reasons
 * CorrectionService would refuse, worked out ahead so the list does not offer
 * an Edit the server will turn down. Filing goes through the usual correction
 * route.
 *
 * The readings are TTI Testing's alone (see LaboratoryService::formatSerology),
 * so the route is gated on lab.record_serology. The donor is blinded as on
 * every laboratory screen (DonorBlinding).
 */
class SerologyResultService
{
    public function __construct(
        private readonly SerologyResultRepository $results,
        private readonly LaboratoryService $laboratoryService,
        private readonly CorrectionService $correctionService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        $page = $this->results
            ->query($facility->id, $filters, $staff->can('donors.view_identity'))
            ->paginate($perPage);

        $donationIds = $page->getCollection()->pluck('donation_id')->all();
        $corrections = $this->results->pendingCorrections($donationIds);
        $released = array_flip($this->results->withReleasedUnits($donationIds));
        $mayFile = CorrectionSubject::Serology->mayBeFiledBy($staff);

        return $page->through(fn (DonationSerology $serology): array => $this->format($serology, $staff, [
            'correction' => $corrections->get($serology->donation_id),
            'released' => isset($released[$serology->donation_id]),
            'may_file' => $mayFile,
        ]));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function csv(User $staff, array $filters): StreamedResponse
    {
        $facility = $this->requireFacility($staff);
        $query = $this->results->query($facility->id, $filters, $staff->can('donors.view_identity'));

        // Counts and filters only. Which marker is never written to the audit
        // trail: the log is read far more widely than this list.
        $this->auditLogger->record($staff, 'laboratory.serology_exported', $facility, [
            'rows' => (clone $query)->count(),
            'filters' => array_intersect_key($filters, array_flip(['search', 'outcome'])),
        ]);

        return response()->streamDownload(function () use ($query, $staff): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'DonationID', 'Bag', 'Donor', 'DonorID', 'BloodGroup',
                ...array_map(fn (SerologyMarker $marker): string => $this->shortLabel($marker), SerologyMarker::cases()),
                'Overall', 'TestedAt', 'TestedBy', 'DonationStatus',
            ], escape: '');

            $query->chunk(300, function ($rows) use ($out, $staff): void {
                foreach ($rows as $serology) {
                    $row = $this->format($serology, $staff);

                    fputcsv($out, array_map(fn ($v): string => $this->cell($v), [
                        $row['code'],
                        $row['bag']['barcode'] ?? '',
                        $row['donor']['full_name'] ?? '',
                        $row['donor']['donor_code'] ?? '',
                        $row['donor']['blood_type'] ?? '',
                        ...array_map(fn (array $marker): string => (string) $marker['result_label'], $row['markers'] ?? []),
                        $row['awaiting_verification'] ? 'Reactive (awaiting verification)' : $row['outcome_label'],
                        $serology->recorded_at?->format('Y-m-d H:i'),
                        $row['recorded_by'],
                        $row['donation_status_label'],
                    ]), escape: '');
                }
            });

            fclose($out);
        }, 'serology-results-'.Str::slug($facility->name).'-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * One row.
     *
     * `locked` says why the panel can no longer be corrected, in the order
     * CorrectionService checks: a reactive panel waiting for verification is
     * verified or returned instead, a verified reactive panel never, a
     * rejected donation's results are locked, and a bag out of quarantine
     * stands on this result. A pending correction is not a lock — the panel
     * waits on its decision. `can.verify` offers the Laboratory Supervisor
     * Verify and Return on a panel waiting for them.
     *
     * `correction` is the pending correction itself, so the supervisor
     * decides it here (project owner, 2026-10-18): who asked, why, and the
     * readings it proposes. `can_decide` is CorrectionService's own rule.
     *
     * @param  array{correction?: CorrectionRequest|null, released?: bool, may_file?: bool}  $state
     * @return array<string, mixed>
     */
    private function format(DonationSerology $serology, User $viewer, array $state = []): array
    {
        $donation = $serology->donation;
        $collection = $donation->collection;
        $section = $this->laboratoryService->formatSerology($serology, $viewer);
        $correction = $state['correction'] ?? null;
        $pending = $correction !== null;

        $locked = match (true) {
            $section['awaiting_verification'] => 'awaiting_verification',
            $section['outcome'] === MarkerResult::Reactive->value => 'reactive',
            $donation->status === DonationStatus::Rejected => 'rejected',
            $state['released'] ?? false => 'units_released',
            default => null,
        };

        return [
            'id' => $serology->id,
            'donation_id' => $donation->id,
            'code' => 'DON-'.str_pad((string) $donation->id, 6, '0', STR_PAD_LEFT),
            'donor' => DonorBlinding::block(
                $donation->donorProfile?->donor,
                $donation->donorProfile?->bloodType?->code,
                $viewer
            ),
            'bag' => $collection === null ? null : [
                'barcode' => $collection->donation_barcode,
                'type' => $collection->blood_bag_type?->label(),
            ],
            ...$section,
            'donation_status' => $donation->status?->value,
            'donation_status_label' => $donation->status?->label(),
            'correction_pending' => $pending,
            'correction' => $correction === null ? null : [
                'id' => $correction->id,
                'requested_by' => $correction->requester
                    ? $correction->requester->signatureName()
                    : null,
                'requested_at' => $correction->created_at?->toISOString(),
                'reason' => $correction->reason,
                // The readings only: confirm_reactive is the filing's, not a reading.
                'changes' => array_intersect_key((array) $correction->changes, array_flip(SerologyMarker::values())),
                'can_decide' => $viewer->can('corrections.approve') && $this->correctionService->mayDecide($viewer, $correction),
            ],
            'locked' => $locked,
            'can' => [
                'correct' => $locked === null && ! $pending && ($state['may_file'] ?? false),
                'verify' => $section['awaiting_verification'] && $viewer->can('lab.verify_serology'),
            ],
        ];
    }

    /**
     * The marker's name as a column heading: the label without its gloss.
     */
    private function shortLabel(SerologyMarker $marker): string
    {
        return trim(Str::before($marker->label(), '('));
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
