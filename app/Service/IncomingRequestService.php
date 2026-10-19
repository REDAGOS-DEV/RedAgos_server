<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Repository\BloodRequestRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reading the incoming queue, and reviewing one request against live stock.
 *
 * Read-only. Everything that changes a request lives in
 * RequestAllocationService or FulfillmentService, so a reviewer can look at a
 * request as often as they like without holding anything. Exporting the
 * queue writes one audit row and changes nothing.
 */
class IncomingRequestService
{
    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly AvailabilityRepository $availabilityRepository,
        private readonly BloodRequestProjector $projector,
        private readonly BloodRequestFormService $formService,
        private readonly RequestStatusResolver $resolver,
        private readonly BloodRequestHistory $history,
        private readonly AuditLogger $auditLogger,
        private readonly TransfusionRequestResolver $transfusionResolver
    ) {}

    /**
     * What a watcher carries to the next blood centre when this one cannot
     * supply everything (project owner, 2026-10-19).
     *
     * The patient's requirement and, per component, how much of it is still
     * asked of nobody: the units another centre can take on by entering the
     * reference at its counter. Audited by reference only, because the slip
     * leaves the building with the patient's name on it.
     *
     * @return array<string, mixed>
     */
    public function referralSlip(User $user, int $requestId): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = $this->bloodRequestRepository->findAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        // Loaded whole: the listing's relation carries only the PTR's stub
        // columns, and the slip needs the patient, blood type and hospital.
        $requirement = $request->transfusion_request_id === null
            ? null
            : TransfusionRequest::query()
                ->with(['items.component', 'bloodType', 'hospital'])
                ->find($request->transfusion_request_id);

        if ($requirement === null) {
            throw $this->refuse(
                409,
                'no_patient_request',
                'This request is not part of a Patient Transfusion Request, so there is nothing for another centre to continue.'
            );
        }
        $figures = $this->transfusionResolver->freshFigures($requirement);

        $lines = $requirement->items->sortBy('id')->values()->map(fn ($item): array => [
            'component' => $item->component?->name,
            'code' => $item->component?->labelCode(),
            'required' => (int) $item->quantity,
            'unallocated' => (int) ($figures->get($item->id)['unallocated'] ?? 0),
        ])->all();

        $user->loadMissing('facility');

        $this->auditLogger->record($user, 'request.referral_slip_printed', $request, [
            'facility_id' => $facilityId,
            'reference_number' => $request->reference_number,
            'transfusion_reference_number' => $requirement->reference_number,
        ]);

        return [
            'reference_number' => $requirement->reference_number,
            'request_reference' => $request->reference_number,
            'hospital' => $requirement->hospital?->name,
            'patient' => $this->projector->patientBlock($requirement),
            'blood_type' => $requirement->bloodType?->code,
            'urgency_label' => $requirement->urgency_level->label(),
            'centre' => $user->facility?->name,
            'lines' => $lines,
            'still_needed' => array_sum(array_column($lines, 'unallocated')),
            'issued_at' => now()->toIso8601String(),
        ];
    }

    /**
     * List requests addressed to the caller's facility.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function queue(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->bloodRequestRepository
            ->paginateAddressedTo($this->requireFacilityId($user), $filters, $perPage)
            ->through(fn (BloodRequest $request): array => $this->format($request));
    }

    /**
     * The queue as CSV: the list's filters, in its order, every page.
     *
     * Audited with the row count and the filters, as the other lists' exports
     * are, because a request names its patient.
     *
     * @param  array<string, mixed>  $filters
     */
    public function csv(User $user, array $filters): StreamedResponse
    {
        $facilityId = $this->requireFacilityId($user);
        $query = $this->bloodRequestRepository->addressedToQuery($facilityId, $filters);

        $user->loadMissing('facility');

        // reorder(): Postgres refuses an ORDER BY beside a bare COUNT(*).
        $this->auditLogger->record($user, 'blood_requests.exported', $user->facility, [
            'rows' => (clone $query)->reorder()->count(),
            'filters' => array_intersect_key(
                $filters,
                array_flip(['search', 'status', 'urgency_level', 'request_source', 'request_purpose', 'open', 'from', 'to'])
            ),
        ]);

        $timezone = (string) config('blood_center.timezone', 'Asia/Manila');

        return response()->streamDownload(function () use ($query, $timezone): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Reference', 'Date', 'Time', 'Hospital', 'Requester', 'Patient', 'BloodTypes', 'Components',
                'Requested', 'Reserved', 'Issued', 'Priority', 'Status', 'Source', 'Purpose',
            ], escape: '');

            $query->chunk(200, function ($requests) use ($out, $timezone): void {
                foreach ($requests as $request) {
                    $row = $this->format($request);
                    $at = $request->request_date === null
                        ? null
                        : CarbonImmutable::instance($request->request_date)->setTimezone($timezone);

                    fputcsv($out, array_map(fn ($value): string => $this->cell($value), [
                        $row['reference_number'],
                        $at?->toDateString(),
                        $at?->format('H:i'),
                        $row['requesting_facility']['name'] ?? '',
                        $row['requester_name'] ?? ($row['is_walk_in'] ? 'Walk-in' : ''),
                        $row['patient']['full_name'] ?? '',
                        implode(' ', $row['blood_types']),
                        $request->items
                            ->map(fn (BloodRequestItem $item): string => ($item->component?->name ?? 'Component').' x'.$item->quantity)
                            ->implode('; '),
                        $row['quantity'],
                        // Held for it and not yet sent.
                        max(0, $row['allocated_count'] - $row['fulfilled_quantity']),
                        $row['fulfilled_quantity'],
                        $row['urgency_label'],
                        $row['status_label'],
                        $row['source_label'],
                        $row['purpose_label'],
                    ]), escape: '');
                }
            });

            fclose($out);
        }, 'incoming-requests-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Count the queue by the states the dashboard shows.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $facilityId = $this->requireFacilityId($user);

        $counts = BloodRequest::query()
            ->addressedTo($facilityId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        // Projected from the enum so every state appears, including the ones
        // this facility currently has none of.
        $totals = [];

        foreach (BloodRequestStatus::values() as $status) {
            $totals[$status] = (int) ($counts[$status] ?? 0);
        }

        $emergencies = BloodRequest::query()
            ->addressedTo($facilityId)
            ->where('urgency_level', UrgencyLevel::Emergency->value)
            ->whereIn('status', [BloodRequestStatus::Pending->value, BloodRequestStatus::Processing->value])
            ->count();

        $awaitingRelease = BloodRequest::query()
            ->addressedTo($facilityId)
            ->whereHas('allocations', fn ($query) => $query->where('status', 'allocated'))
            ->count();

        // Open requests by where they were keyed in, so the queue can show how
        // much of its work arrived at the counter rather than through the
        // portal.
        $openBySource = BloodRequest::query()
            ->addressedTo($facilityId)
            ->open()
            ->groupBy('request_source')
            ->selectRaw('request_source, COUNT(*) as aggregate')
            ->pluck('aggregate', 'request_source')
            ->all();

        $bySource = [];

        foreach (RequestSource::values() as $source) {
            $bySource[$source] = (int) ($openBySource[$source] ?? 0);
        }

        // Open requests by what they are for: a patient's transfusion or the
        // hospital's own shelves. The list keeps the two apart (project
        // owner, 2026-10-19) and counts each on its tab.
        $openByPurposeCounts = BloodRequest::query()
            ->addressedTo($facilityId)
            ->open()
            ->groupBy('request_purpose')
            ->selectRaw('request_purpose, COUNT(*) as aggregate')
            ->pluck('aggregate', 'request_purpose')
            ->all();

        $byPurpose = [];

        foreach (RequestPurpose::values() as $purpose) {
            $byPurpose[$purpose] = (int) ($openByPurposeCounts[$purpose] ?? 0);
        }

        return [
            'totals' => $totals,
            'open_emergencies' => $emergencies,
            'awaiting_release' => $awaitingRelease,
            'open_by_source' => $bySource,
            'open_by_purpose' => $byPurpose,
            'as_of' => OperationalDay::today()->toIso8601String(),
        ];
    }

    /**
     * Show an incoming request's history, oldest first.
     *
     * @return array<string, mixed>
     */
    public function history(User $user, int $requestId): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = $this->bloodRequestRepository->findAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        return [
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'events' => $this->history->timeline($request),
        ];
    }

    /**
     * Show one incoming request beside the stock that could fill it.
     *
     * The availability figure is read at the moment of review and is advisory
     * in exactly the way a search result is: it is not a hold, and allocation
     * re-checks every unit under a lock before anything is reserved.
     *
     * @return array<string, mixed>
     */
    public function review(User $user, int $requestId): array
    {
        $facilityId = $this->requireFacilityId($user);

        $request = $this->bloodRequestRepository->findAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $figures = $this->resolver->figures($request);

        // Stock is counted per line, not per request: a form asking for packed
        // cells and platelets has two different shelves to answer it from, and
        // one figure covering both would say nothing useful about either.
        $lines = $request->items->sortBy('id')->values()->map(function (BloodRequestItem $item) use ($facilityId, $figures): array {
            $available = $this->availabilityRepository->availableAt(
                $facilityId,
                (int) $item->blood_type_id,
                (int) $item->component_id,
                OperationalDay::todayAsDate()
            );

            $figure = $figures->get($item->id);

            // What this facility still has to find for the line: nothing
            // already held or released, and nothing once the rest of the line
            // was closed.
            $outstanding = (int) ($figure['allocatable'] ?? 0);

            return [
                'request_item_id' => $item->id,
                'blood_type' => [
                    'id' => $item->blood_type_id,
                    'code' => $item->bloodType?->code,
                ],
                'component' => [
                    'id' => $item->component_id,
                    'name' => $item->component?->name,
                ],
                // What the line asked for, beside what can answer it. Without
                // this the reviewer sees a stock figure with nothing to judge
                // it against, which on a multi-component request is worse than
                // no figure at all.
                'requested' => $item->quantity,
                'available' => $available,
                'outstanding' => $outstanding,
                'can_fully_cover' => $available >= $outstanding,
                'can_cover_now' => min($available, $outstanding),
                'reserved' => (int) ($figure['reserved'] ?? 0),
                'fulfilled' => (int) ($figure['fulfilled'] ?? 0),
                'received' => (int) ($figure['received'] ?? 0),
                'remaining' => (int) ($figure['remaining'] ?? $item->quantity),
                'closed' => (bool) ($figure['closed'] ?? false),
                'line_status' => ($figure['status'] ?? null)?->value,
                'line_status_label' => ($figure['status'] ?? null)?->label(),
            ];
        });

        $outstanding = (int) $lines->sum('outstanding');

        return [
            'request' => $this->format($request, withAllocations: true),
            'inventory' => [
                'lines' => $lines->all(),
                'outstanding' => $outstanding,
                'can_fully_cover' => $lines->every(fn (array $line): bool => $line['can_fully_cover']),
                'can_cover_now' => (int) $lines->sum('can_cover_now'),
                'advisory' => true,
                'as_of' => OperationalDay::today()->toIso8601String(),
            ],
        ];
    }

    /**
     * Render one incoming request as the DOH request form.
     *
     * Scoped to requests addressed to the caller's facility, so a centre can
     * print the paperwork for work it has actually been asked to do and
     * nothing else. The same service builds the hospital's copy, so the two
     * are the same document.
     */
    public function form(User $user, int $requestId): Response
    {
        $facilityId = $this->requireFacilityId($user);
        $request = $this->bloodRequestRepository->findAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        return $this->formService->download($request);
    }

    /**
     * Project a request for the fulfilling facility's screens.
     *
     * @return array<string, mixed>
     */
    private function format(BloodRequest $request, bool $withAllocations = false): array
    {
        return $this->projector->project($request, $withAllocations);
    }

    /**
     * One CSV cell, defused: a value a spreadsheet would read as a formula
     * is prefixed with a quote, as the other lists' exports do.
     */
    private function cell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(
            404,
            'facility_missing',
            'This account is not linked to a facility.'
        );
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
