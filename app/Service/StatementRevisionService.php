<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Models\Billing;
use App\Models\BillingRevision;
use App\Models\BloodRequest;
use App\Models\User;
use App\Repository\DocumentSequenceRepository;
use App\Support\DocumentNumbering;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

/**
 * Issued Statements of Account: frozen revisions of a statement, and their printed form.
 *
 * The live statement (billings) keeps changing as units are reserved; what a
 * payer is shown must not. Each issue, checkout and payment is pinned to a
 * revision frozen at that moment. A revision is reused while nothing on the
 * statement has changed, so printing twice does not consume a second number,
 * and a new one is frozen the moment the bill or the balance moves.
 *
 * The printed document is a Statement of Account, not an invoice or an
 * official receipt — decided by the project owner on 2026-10-10, pending
 * finance confirmation of the legal requirements.
 */
class StatementRevisionService
{
    public function __construct(
        private readonly StatementFigures $figures,
        private readonly DocumentSequenceRepository $sequences,
        private readonly FacilityLogoService $facilityLogoService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Reuse the latest revision if the live figures still match it, otherwise freeze a new one.
     *
     * Must run inside a transaction that already holds the request, then the
     * billing row (BillingService::lockForMutation()). Every figure is read
     * under those locks, and the document number is taken last, from the
     * issuing facility's statement counter.
     *
     * @return array{0: BillingRevision, 1: bool} The revision, and whether it was frozen just now.
     */
    public function issueOrReuse(BloodRequest $lockedRequest, Billing $lockedBilling, ?User $actor, string $reason): array
    {
        $lines = $this->figures->linesFor($lockedRequest);
        $total = $this->figures->totalOf($lines);
        $collected = $this->figures->collectedFor($lockedBilling);
        $status = $lockedBilling->status;
        $due = $this->amountDue($status, $total, $collected);

        $latest = BillingRevision::query()
            ->with('items')
            ->where('billing_id', $lockedBilling->id)
            ->orderByDesc('revision_number')
            ->first();

        if ($latest !== null && $this->matches($latest, $lines, $total, $collected, $status)) {
            return [$latest, false];
        }

        $issuingFacilityId = (int) $lockedRequest->target_facility_id;
        $sequence = $this->sequences->next($issuingFacilityId, 'statement');

        $revision = BillingRevision::query()->create([
            'billing_id' => $lockedBilling->id,
            'revision_number' => ($latest?->revision_number ?? 0) + 1,
            'document_number' => DocumentNumbering::format('SOA', $issuingFacilityId, $sequence),
            'issuing_facility_id' => $issuingFacilityId,
            'payer_facility_id' => (int) $lockedRequest->facility_id,
            'currency' => 'PHP',
            'total_amount' => Money::toDecimal($total),
            'collected_at_issue' => Money::toDecimal($collected),
            'amount_due' => Money::toDecimal($due),
            'statement_only' => $status === BillingStatus::StatementOnly,
            'billing_status' => $status,
            'reason' => $reason,
            'created_by' => $actor?->id,
        ]);

        foreach ($lines as $line) {
            $revision->items()->create([
                'request_item_id' => $line['request_item_id'],
                'component_id' => $line['component_id'],
                'component_name' => $line['component_name'],
                'quantity' => $line['quantity'],
                'unit_price' => Money::toDecimal($line['unit_price']),
                'line_total' => Money::toDecimal($line['line_total']),
            ]);
        }

        return [$revision->load('items'), true];
    }

    /**
     * Every revision issued for a statement, oldest first, as the API shows them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(Billing $billing): array
    {
        return $billing->revisions()->with('items')->get()
            ->map(fn (BillingRevision $revision): array => $this->format($revision))
            ->all();
    }

    /**
     * Project one revision for the API. Amounts are decimal strings.
     *
     * @return array<string, mixed>
     */
    public function format(BillingRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'document_number' => $revision->document_number,
            'revision_number' => $revision->revision_number,
            'reason' => $revision->reason,
            'billing_status' => $revision->billing_status->value,
            'billing_status_label' => $revision->billing_status->label(),
            'statement_only' => $revision->statement_only,
            'currency' => $revision->currency,
            'total_amount' => $revision->total_amount,
            'collected_at_issue' => $revision->collected_at_issue,
            'amount_due' => $revision->amount_due,
            'issued_at' => $revision->created_at?->toIso8601String(),
            'lines' => $revision->items->map(fn ($item): array => [
                'component_name' => $item->component_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])->all(),
        ];
    }

    /**
     * Find a revision issued by the caller's own centre.
     */
    public function findIssuedBy(int $revisionId, int $facilityId): BillingRevision
    {
        return BillingRevision::query()
            ->whereKey($revisionId)
            ->where('issuing_facility_id', $facilityId)
            ->first()
            ?? throw $this->notFound();
    }

    /**
     * Find a revision addressed to the caller's own hospital.
     */
    public function findAddressedTo(int $revisionId, int $facilityId): BillingRevision
    {
        return BillingRevision::query()
            ->whereKey($revisionId)
            ->where('payer_facility_id', $facilityId)
            ->first()
            ?? throw $this->notFound();
    }

    /**
     * Render one revision as its printed Statement of Account.
     *
     * Rendered from the revision's own rows, never the live statement, so the
     * same revision prints the same document every time.
     */
    public function pdf(BillingRevision $revision, User $viewer): Response
    {
        $revision->loadMissing(['items', 'issuingFacility', 'payerFacility', 'billing.request']);
        $request = $revision->billing?->request;

        $this->auditLogger->record($viewer, 'billing.statement_downloaded', $revision->billing, [
            'revision_id' => $revision->id,
            'document_number' => $revision->document_number,
            'facility_id' => $viewer->facility_id,
        ]);

        // dompdf cannot decode a PNG without GD; the statement still prints without the logo.
        $images = extension_loaded('gd');

        return Pdf::loadView('pdf.billing-statement', [
            'revision' => $revision,
            'request' => $request,
            // A weekly order has no patient; a Patient Transfusion statement is
            // the patient's, and names them.
            'patient' => $revision->statement_only ? null : $request?->patientFullName(),
            'logo' => $images ? $this->facilityLogoService->dataUriFor($revision->issuingFacility) : null,
        ])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->download("{$revision->document_number}.pdf");
    }

    /**
     * What a statement leaves to be paid, in centavos.
     *
     * A collectible statement owes its total less what it has collected. A
     * statement-only one owes its total too, but to be settled outside
     * RedAgos. A voided or subsidised one owes nothing.
     */
    private function amountDue(BillingStatus $status, int $total, int $collected): int
    {
        return $status->isCollectible() || $status === BillingStatus::StatementOnly
            ? max($total - $collected, 0)
            : 0;
    }

    /**
     * Whether an issued revision still says exactly what the live statement says.
     *
     * Compared line by line, not component by component: a weekly request can
     * ask for the same component in two blood types, and those are two lines.
     *
     * @param  array<int, array{request_item_id: int, component_id: int, quantity: int, unit_price: int}>  $lines
     */
    private function matches(BillingRevision $revision, array $lines, int $total, int $collected, BillingStatus $status): bool
    {
        if (Money::toCentavos($revision->total_amount) !== $total
            || Money::toCentavos($revision->collected_at_issue) !== $collected
            || $revision->billing_status !== $status) {
            return false;
        }

        $issued = $revision->items
            ->map(fn ($item): string => $item->request_item_id.':'.$item->component_id.':'.$item->quantity.':'.Money::toCentavos($item->unit_price))
            ->sort()->values()->all();

        $live = collect($lines)
            ->map(fn (array $line): string => $line['request_item_id'].':'.$line['component_id'].':'.$line['quantity'].':'.$line['unit_price'])
            ->sort()->values()->all();

        return $issued === $live;
    }

    private function notFound(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'Statement not found.',
            'code' => 'statement_not_found',
        ], 404));
    }
}
