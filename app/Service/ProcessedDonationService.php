<?php

namespace App\Service;

use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\Facility;
use App\Models\User;
use App\Repository\ProcessedDonationRepository;
use App\Support\BagNumbers;
use App\Support\DonorBlinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Processing's Processed list (project owner, 2026-10-19): every donation it
 * has recorded a component breakdown for, and where the hand-over to Issuance
 * is made.
 *
 * The breakdown is recorded on Component Processing; a processed unit leaves
 * that queue and waits here. This lists, exports, and says of each row
 * whether it may still be handed over or rejected — the reasons
 * LaboratoryService would refuse, worked out ahead so the list does not offer
 * an action the server will turn down. The act itself goes through the
 * laboratory status route, as it always has.
 *
 * Processing works blind (DonorBlinding), and like the bench it sees whether
 * each Testing department has cleared the donation, never a reading.
 */
class ProcessedDonationService
{
    public function __construct(
        private readonly ProcessedDonationRepository $processed,
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

        $page = $this->processed
            ->query($facility->id, $filters, $staff->can('donors.view_identity'))
            ->paginate($perPage);

        $corrections = $this->processed->pendingCorrections($page->getCollection()->pluck('id')->all());
        $names = $this->processed->componentNames($corrections
            ->flatMap(fn (CorrectionRequest $correction): array => array_column($this->proposedBags($correction), 'component_id'))
            ->unique()
            ->values()
            ->all());
        $mayFile = CorrectionSubject::Components->mayBeFiledBy($staff);

        return $page->through(fn (Donation $donation): array => $this->format($donation, $staff, [
            'correction' => $corrections->get($donation->id),
            'names' => $names,
            'may_file' => $mayFile,
        ]));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function csv(User $staff, array $filters): StreamedResponse
    {
        $facility = $this->requireFacility($staff);
        $query = $this->processed->query($facility->id, $filters, $staff->can('donors.view_identity'));

        $this->auditLogger->record($staff, 'laboratory.processed_exported', $facility, [
            'rows' => (clone $query)->count(),
            'filters' => array_intersect_key($filters, array_flip(['search', 'status'])),
        ]);

        return response()->streamDownload(function () use ($query, $staff): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'DonationID', 'Bag', 'Donor', 'DonorID', 'BloodGroup', 'Components', 'Bags', 'ProcessedVolumeMl',
                'CollectedVolumeMl', 'ProcessedAt', 'ProcessedBy', 'TTICleared', 'ABORhCleared', 'HandOver',
                'UnitsBookedIn', 'RejectionReason',
            ], escape: '');

            $query->chunk(300, function ($rows) use ($out, $staff): void {
                foreach ($rows as $donation) {
                    $row = $this->format($donation, $staff);

                    fputcsv($out, array_map(fn ($v): string => $this->cell($v), [
                        $row['code'],
                        $row['bag']['barcode'] ?? '',
                        $row['donor']['full_name'] ?? '',
                        $row['donor']['donor_code'] ?? '',
                        $row['donor']['blood_type'] ?? '',
                        implode('; ', array_map(
                            fn (array $bag): string => trim(($bag['bag_number'] ?? '').' '.$bag['component'].' '.($bag['volume_ml'] ? $bag['volume_ml'].' mL' : '')),
                            $row['components']
                        )),
                        $row['bag_count'],
                        $row['processed_volume_ml'],
                        $row['collected_volume_ml'],
                        $donation->components->max('created_at')?->format('Y-m-d H:i'),
                        $row['processed_by'],
                        ($row['clearances']['tti'] ?? false) ? 'Yes' : 'No',
                        ($row['clearances']['immunohematology'] ?? false) ? 'Yes' : 'No',
                        $row['status_label'],
                        $row['units_booked'],
                        $row['rejection_reason'],
                    ]), escape: '');
                }
            });

            fclose($out);
        }, 'processed-'.Str::slug($facility->name).'-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * One row.
     *
     * `status` is where the processed unit stands: awaiting_handover until
     * Processing hands it over (the donation becomes `completed`) or rejects
     * it. `can.hand_over` and `can.reject` mirror LaboratoryService's guards
     * for those two acts, and the lab.update_status the route needs: the
     * Processing Laboratory Assistant records breakdowns but hands nothing
     * over.
     *
     * The breakdown is corrected here (project owner, 2026-10-19), as TTI
     * Testing corrects a panel on Serology Results. `locked` says why it can
     * no longer be, as CorrectionService::guardCorrectable would refuse it,
     * and `can.correct` offers Edit only when the correction route would take
     * it. `correction` is the pending one itself, so the component
     * technologist decides it here: who asked, why, the bags it proposes, and
     * `can_decide`, CorrectionService's own rule.
     *
     * @param  array{correction?: CorrectionRequest|null, names?: array<int, string>, may_file?: bool}  $state
     * @return array<string, mixed>
     */
    private function format(Donation $donation, User $viewer, array $state = []): array
    {
        $collection = $donation->collection;
        $rows = $donation->components->sortBy('id')->values();
        $latest = $rows->sortByDesc('created_at')->first();
        $numbers = BagNumbers::forRows($donation);
        $open = in_array($donation->status, [DonationStatus::Collected, DonationStatus::Tested], true);
        $mayClose = $open && $viewer->can('lab.update_status');
        $correction = $state['correction'] ?? null;
        $names = $state['names'] ?? [];

        $locked = match ($donation->status) {
            DonationStatus::Completed => 'handed_over',
            DonationStatus::Rejected => 'rejected',
            default => null,
        };

        $status = match ($donation->status) {
            DonationStatus::Completed => 'handed_over',
            DonationStatus::Rejected => 'rejected',
            default => 'awaiting_handover',
        };

        return [
            'id' => $donation->id,
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
            // The same shape as the bench's breakdown (LaboratoryService::formatComponents).
            'components' => $rows->map(fn (DonationComponent $c): array => [
                'id' => $c->id,
                'component_id' => $c->component_id,
                'component' => $c->component?->name,
                // The suffix on its bag number (PRBC, FFP…): the list's chip.
                'code' => $c->component?->labelCode(),
                'volume_ml' => $c->volume_ml,
                'quantity' => $c->quantity,
                'bag_number' => $numbers[$c->id] ?? null,
            ])->all(),
            'bag_count' => (int) $rows->sum('quantity'),
            'processed_volume_ml' => $rows->whereNotNull('volume_ml')->isEmpty() ? null : (int) $rows->sum('volume_ml'),
            'collected_volume_ml' => $donation->volume_ml,
            'processed_at' => $latest?->created_at?->toISOString(),
            'processed_by' => $latest?->declarer === null ? null : trim($latest->declarer->first_name.' '.$latest->declarer->last_name),
            'clearances' => $this->laboratoryService->formatClearances($donation),
            'status' => $status,
            'status_label' => match ($status) {
                'handed_over' => 'Handed over',
                'rejected' => 'Rejected',
                default => 'Awaiting hand-over',
            },
            'donation_status' => $donation->status?->value,
            'donation_status_label' => $donation->status?->label(),
            'rejection_reason' => $donation->rejection_reason,
            // Bags Issuance has booked in at Stock Intake so far.
            'units_booked' => (int) ($donation->blood_units_count ?? 0),
            'correction_pending' => $correction !== null,
            'correction' => $correction === null ? null : [
                'id' => $correction->id,
                'requested_by' => $correction->requester
                    ? trim($correction->requester->first_name.' '.$correction->requester->last_name)
                    : null,
                'requested_at' => $correction->created_at?->toISOString(),
                'reason' => $correction->reason,
                // The bags it proposes, in the breakdown's own shape.
                'components' => array_map(fn (array $bag): array => [
                    'component_id' => (int) $bag['component_id'],
                    'component' => $names[(int) $bag['component_id']] ?? null,
                    'volume_ml' => isset($bag['volume_ml']) ? (int) $bag['volume_ml'] : null,
                    'quantity' => 1,
                ], $this->proposedBags($correction)),
                'can_decide' => $viewer->can('corrections.approve') && $this->correctionService->mayDecide($viewer, $correction),
            ],
            'locked' => $locked,
            'can' => [
                'hand_over' => $mayClose,
                'reject' => $mayClose,
                'correct' => $locked === null && $correction === null && ($state['may_file'] ?? false),
            ],
        ];
    }

    /**
     * The bags a breakdown correction proposes, as it was validated when filed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function proposedBags(CorrectionRequest $correction): array
    {
        return array_values(array_filter(
            (array) ($correction->changes['components'] ?? []),
            fn ($bag): bool => is_array($bag) && isset($bag['component_id'])
        ));
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
