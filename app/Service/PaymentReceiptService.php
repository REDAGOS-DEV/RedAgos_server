<?php

namespace App\Service;

use App\Models\BillingRevision;
use App\Models\BloodRequest;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Repository\DocumentSequenceRepository;
use App\Support\DocumentNumbering;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

/**
 * Payment Acknowledgement Receipts: one per confirmed payment, never for anything less.
 *
 * Issued in the same transaction that records the payment, cash or gateway,
 * and numbered AR-{facility}-{seq} from the issuing centre's receipt counter.
 * Everything the printed receipt shows is frozen in its snapshot, including
 * the balance before and after the payment, so a partial payment can never
 * read as settling the whole statement and a later change can never alter a
 * receipt already handed over.
 *
 * Not a BIR official receipt, and it says so — decided by the project owner on
 * 2026-10-10, pending finance confirmation.
 */
class PaymentReceiptService
{
    public function __construct(
        private readonly DocumentSequenceRepository $sequences,
        private readonly FacilityLogoService $facilityLogoService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Issue the receipt for a payment just recorded.
     *
     * Must run inside the transaction that recorded the payment, after the
     * request and billing locks; the receipt number is taken last.
     *
     * @param  int  $balanceBefore  What was outstanding immediately before this payment, in centavos.
     */
    public function issue(
        Payment $payment,
        BloodRequest $request,
        ?BillingRevision $revision,
        int $balanceBefore,
        ?User $issuer,
        ?string $payerName,
        ?PaymentReceipt $replaces = null
    ): PaymentReceipt {
        $facilityId = (int) $request->target_facility_id;
        $number = DocumentNumbering::format('AR', $facilityId, $this->sequences->next($facilityId, 'receipt'));
        $paid = Money::toCentavos($payment->amount_paid);
        $balanceAfter = $balanceBefore - $paid;
        $issuedAt = now();

        $request->loadMissing(['requestingFacility', 'targetFacility']);

        return PaymentReceipt::query()->create([
            'payment_id' => $payment->id,
            'issuing_facility_id' => $facilityId,
            'receipt_number' => $number,
            'replaces_receipt_id' => $replaces?->id,
            'issued_at' => $issuedAt,
            'issued_by' => $issuer?->id,
            'snapshot' => [
                'receipt_number' => $number,
                'issued_at' => $issuedAt->toIso8601String(),
                'issuing_facility' => [
                    'name' => $request->targetFacility?->name,
                    'address' => $request->targetFacility?->address,
                    'doh_license_number' => $request->targetFacility?->doh_license_number,
                    'phone' => $request->targetFacility?->phone,
                    'email' => $request->targetFacility?->email,
                ],
                'payer_name' => $payerName,
                'received_by' => $issuer ? trim($issuer->first_name.' '.$issuer->last_name) : null,
                'request' => [
                    'reference_number' => $request->reference_number,
                    'patient_name' => $request->patientFullName(),
                    'requesting_facility' => $request->requestingFacility?->name,
                ],
                'statement' => $revision ? [
                    'document_number' => $revision->document_number,
                    'revision_number' => $revision->revision_number,
                    'total_amount' => $revision->total_amount,
                    'amount_due' => $revision->amount_due,
                ] : null,
                // What the payment was for, copied from the statement it was made
                // against, so the receipt lists it without reading anything else.
                'lines' => $revision ? $revision->items->map(fn ($item): array => [
                    'component_name' => $item->component_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ])->values()->all() : [],
                'balance_before' => Money::toDecimal($balanceBefore),
                'amount_paid' => Money::toDecimal($paid),
                'balance_after' => Money::toDecimal($balanceAfter),
                'is_partial' => $balanceAfter > 0,
                'payment' => [
                    'method' => $payment->payment_method->value,
                    'method_label' => $payment->payment_method->label(),
                    'reference_number' => $payment->reference_number,
                    'source' => $payment->source->value,
                    'paid_at' => $payment->payment_date?->toIso8601String(),
                ],
                'replaces_receipt_number' => $replaces?->receipt_number,
            ],
        ]);
    }

    /**
     * Void a corrected payment's receipt and issue its replacement.
     *
     * The replacement keeps the balance the payment was made against and the
     * payer named on the original, shows the corrected amount, method and
     * reference, and names the receipt it replaces; the original stays on
     * record, voided. A payment recorded before receipts existed has none to
     * replace, and gets none now.
     *
     * Must run inside the transaction that applied the correction.
     */
    public function reissue(Payment $lockedPayment, BloodRequest $lockedRequest, User $actor, string $reason): ?PaymentReceipt
    {
        $current = $lockedPayment->receipts()->active()->first();

        if ($current === null) {
            return null;
        }

        $current->forceFill([
            'voided_at' => now(),
            'voided_by' => $actor->id,
            'void_reason' => $reason,
        ])->save();

        return $this->issue(
            $lockedPayment,
            $lockedRequest,
            $lockedPayment->revision,
            Money::toCentavos($current->snapshot['balance_before'] ?? '0'),
            $actor,
            $current->snapshot['payer_name'] ?? null,
            $current
        );
    }

    /**
     * Project one receipt for the API. Amounts are decimal strings.
     *
     * @return array<string, mixed>
     */
    public function format(PaymentReceipt $receipt): array
    {
        $snapshot = $receipt->snapshot;

        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'issued_at' => $receipt->issued_at?->toIso8601String(),
            'amount_paid' => $snapshot['amount_paid'] ?? null,
            'balance_after' => $snapshot['balance_after'] ?? null,
            'is_partial' => (bool) ($snapshot['is_partial'] ?? false),
            'payment_method_label' => $snapshot['payment']['method_label'] ?? null,
            'statement_document_number' => $snapshot['statement']['document_number'] ?? null,
            'voided' => $receipt->isVoided(),
            'void_reason' => $receipt->void_reason,
            'replaces_receipt_number' => $snapshot['replaces_receipt_number'] ?? null,
            // The rest of the snapshot, for showing the receipt on screen as it prints.
            'balance_before' => $snapshot['balance_before'] ?? null,
            'payer_name' => $snapshot['payer_name'] ?? null,
            'received_by' => $snapshot['received_by'] ?? null,
            'issuing_facility' => $snapshot['issuing_facility'] ?? null,
            'request' => $snapshot['request'] ?? null,
            'statement' => $snapshot['statement'] ?? null,
            // Without its reference number: the hospital reads this too, and
            // references go only to whoever may record payments (with the payment).
            'payment' => [
                'method_label' => $snapshot['payment']['method_label'] ?? null,
                'source' => $snapshot['payment']['source'] ?? null,
                'paid_at' => $snapshot['payment']['paid_at'] ?? null,
            ],
            // Empty on a receipt issued before receipts carried their lines.
            'lines' => $snapshot['lines'] ?? [],
        ];
    }

    /**
     * Find a receipt issued by the caller's own centre.
     */
    public function findIssuedBy(int $receiptId, int $facilityId): PaymentReceipt
    {
        return PaymentReceipt::query()
            ->whereKey($receiptId)
            ->where('issuing_facility_id', $facilityId)
            ->first()
            ?? throw $this->notFound();
    }

    /**
     * Find a receipt for a request the caller's own hospital raised.
     */
    public function findForRequester(int $receiptId, int $facilityId): PaymentReceipt
    {
        return PaymentReceipt::query()
            ->whereKey($receiptId)
            ->whereHas('payment.billing.request', fn ($query) => $query->raisedBy($facilityId))
            ->first()
            ?? throw $this->notFound();
    }

    /**
     * Render one receipt from its snapshot.
     */
    public function pdf(PaymentReceipt $receipt, User $viewer): Response
    {
        $receipt->loadMissing('issuingFacility');

        $this->auditLogger->record($viewer, 'billing.receipt_downloaded', $receipt->payment?->billing, [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'facility_id' => $viewer->facility_id,
        ]);

        $images = extension_loaded('gd');

        return Pdf::loadView('pdf.payment-receipt', [
            'receipt' => $receipt,
            'snapshot' => $receipt->snapshot,
            'logo' => $images ? $this->facilityLogoService->dataUriFor($receipt->issuingFacility) : null,
        ])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->download("{$receipt->receipt_number}.pdf");
    }

    private function notFound(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'Receipt not found.',
            'code' => 'receipt_not_found',
        ], 404));
    }
}
