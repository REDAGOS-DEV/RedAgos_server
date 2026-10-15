<?php

namespace App\Service;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\BillingRevision;
use App\Models\BloodRequest;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Repository\DocumentSequenceRepository;
use App\Support\DocumentNumbering;
use App\Support\FacilityMonogram;
use App\Support\Money;
use App\Support\OperationalDay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

/**
 * Payment Acknowledgement Receipts: one for each payment handed over, never for anything less.
 *
 * Issued in the same transaction that records the payment, cash or gateway,
 * and numbered AR-{facility}-{seq} from the issuing centre's receipt counter.
 * A payment split across methods at the counter is one receipt that lists each
 * part (project owner, 2026-10-15), not one receipt per method.
 *
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
     * Issue the receipt for a payment just recorded, or one receipt for every part of a split.
     *
     * Must run inside the transaction that recorded the payments, after the
     * request and billing locks; the receipt number is taken last.
     *
     * The snapshot's `payment` describes the whole of what was handed over:
     * a split reads as "Cash + Card", with the cash part's tendered amount and
     * change. `tenders` lists each part with its own method, amount and
     * reference.
     *
     * @param  Payment|array<int, Payment>  $payments  In the order they were recorded.
     * @param  int  $balanceBefore  What was outstanding immediately before them, in centavos.
     */
    public function issue(
        Payment|array $payments,
        BloodRequest $request,
        ?BillingRevision $revision,
        int $balanceBefore,
        ?User $issuer,
        ?string $payerName,
        ?PaymentReceipt $replaces = null
    ): PaymentReceipt {
        $payments = is_array($payments) ? array_values($payments) : [$payments];
        $first = $payments[0];
        $cash = collect($payments)->first(fn (Payment $payment): bool => $payment->payment_method === PaymentMethod::Cash);
        $split = count($payments) > 1;

        $facilityId = (int) $request->target_facility_id;
        $number = DocumentNumbering::format('AR', $facilityId, $this->sequences->next($facilityId, 'receipt'));
        $paid = array_sum(array_map(fn (Payment $payment): int => Money::toCentavos($payment->amount_paid), $payments));
        $balanceAfter = $balanceBefore - $paid;
        $issuedAt = now();

        $request->loadMissing(['requestingFacility', 'targetFacility']);

        $receipt = PaymentReceipt::query()->create([
            'payment_id' => $first->id,
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
                    // The logo the receipt is issued with, so a reprint shows
                    // the one on the copy the payer was handed.
                    'logo_path' => $request->targetFacility?->logo_path,
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
                    'method' => $split ? 'split' : $first->payment_method->value,
                    'method_label' => implode(' + ', array_map(fn (Payment $payment): string => $payment->payment_method->label(), $payments)),
                    // A split's references are on its parts, under tenders.
                    'reference_number' => $split ? null : $first->reference_number,
                    // Card only: the cardholder's last name. Kept beside the
                    // reference and, like it, off the hospital's projection.
                    'additional_information' => $split ? null : $first->additional_information,
                    'source' => $first->source->value,
                    'paid_at' => $first->payment_date?->toIso8601String(),
                    // Cash at the counter: what was handed over, and the change
                    // given back. A split has at most one cash part.
                    'amount_tendered' => $cash?->amount_tendered,
                    'change_given' => $cash?->change_given,
                    'cash_session_number' => $first->cashSession?->session_number,
                ],
                'tenders' => array_map(fn (Payment $payment): array => [
                    'payment_id' => $payment->id,
                    'method' => $payment->payment_method->value,
                    'method_label' => $payment->payment_method->label(),
                    'amount_paid' => Money::toDecimal(Money::toCentavos($payment->amount_paid)),
                    'reference_number' => $payment->reference_number,
                    'additional_information' => $payment->additional_information,
                    'amount_tendered' => $payment->amount_tendered,
                    'change_given' => $payment->change_given,
                ], $payments),
                'replaces_receipt_number' => $replaces?->receipt_number,
            ],
        ]);

        $receipt->payments()->attach(array_map(fn (Payment $payment): int => $payment->id, $payments));

        return $receipt;
    }

    /**
     * Void a corrected payment's receipt and issue its replacement.
     *
     * The replacement keeps the balance the payment was made against and the
     * payer named on the original, shows the corrected amount, method and
     * reference, and names the receipt it replaces; the original stays on
     * record, voided. A split's receipt is reissued whole, every part still
     * on it, with the corrected one as it now stands. A payment recorded before
     * receipts existed has none to replace, and gets none now.
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

        $payments = $this->stillReceived($current)
            ->map(fn (Payment $payment): Payment => $payment->is($lockedPayment) ? $lockedPayment : $payment)
            ->all();

        return $this->issue(
            $payments,
            $lockedRequest,
            $lockedPayment->revision,
            Money::toCentavos($current->snapshot['balance_before'] ?? '0'),
            $actor,
            $current->snapshot['payer_name'] ?? null,
            $current
        );
    }

    /**
     * Void the receipt of a payment being voided, and reissue it for any part of a split still standing.
     *
     * A split's receipt covers every part, so voiding one part must not leave
     * the others without a receipt. They get a new one for what they still add
     * up to, made against the same balance and naming the receipt it replaces.
     *
     * Must run inside the transaction that voids the payment, after the payment
     * has been marked voided.
     *
     * @return array{0: PaymentReceipt|null, 1: PaymentReceipt|null} The receipt voided, and its replacement if there is one.
     */
    public function voidFor(Payment $lockedPayment, BloodRequest $lockedRequest, User $approver, string $reason): array
    {
        $current = $lockedPayment->receipts()->active()->first();

        if ($current === null) {
            return [null, null];
        }

        $current->forceFill([
            'voided_at' => now(),
            'voided_by' => $approver->id,
            'void_reason' => 'Payment voided: '.mb_substr($reason, 0, 230),
        ])->save();

        $standing = $this->stillReceived($current)
            ->reject(fn (Payment $payment): bool => $payment->is($lockedPayment))
            ->values()
            ->all();

        if ($standing === []) {
            return [$current, null];
        }

        return [$current, $this->issue(
            $standing,
            $lockedRequest,
            $standing[0]->revision,
            Money::toCentavos($current->snapshot['balance_before'] ?? '0'),
            $approver,
            $current->snapshot['payer_name'] ?? null,
            $current
        )];
    }

    /**
     * The payments on a receipt that have not been voided, in the order they were recorded.
     *
     * @return Collection<int, Payment>
     */
    private function stillReceived(PaymentReceipt $receipt): Collection
    {
        return $receipt->payments()
            ->with('cashSession')
            ->get()
            ->reject(fn (Payment $payment): bool => $payment->status === PaymentStatus::Voided)
            ->values();
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
            // So a screen can open the statement and the payment it belongs to.
            'payment_id' => $receipt->payment_id,
            'request_id' => $receipt->payment?->billing?->request_id,
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
            // The file path stays on the server; the screen gets a signed link to it.
            'issuing_facility' => isset($snapshot['issuing_facility']) ? [
                ...array_diff_key($snapshot['issuing_facility'], ['logo_path' => true]),
                'logo_url' => $this->facilityLogoService->urlForPath(
                    $receipt->issuingFacility,
                    $snapshot['issuing_facility']['logo_path'] ?? null
                ),
            ] : null,
            'request' => $snapshot['request'] ?? null,
            'statement' => $snapshot['statement'] ?? null,
            // Without its reference number: the hospital reads this too, and
            // references go only to whoever may record payments (with the payment).
            'payment' => [
                'method_label' => $snapshot['payment']['method_label'] ?? null,
                'source' => $snapshot['payment']['source'] ?? null,
                'paid_at' => $snapshot['payment']['paid_at'] ?? null,
                'amount_tendered' => $snapshot['payment']['amount_tendered'] ?? null,
                'change_given' => $snapshot['payment']['change_given'] ?? null,
                'cash_session_number' => $snapshot['payment']['cash_session_number'] ?? null,
            ],
            // Each part of a payment split across methods, without its
            // reference, for the same reason as above. A receipt issued
            // before receipts listed their parts has none.
            'tenders' => array_map(fn (array $tender): array => [
                'method_label' => $tender['method_label'] ?? null,
                'amount_paid' => $tender['amount_paid'] ?? null,
                'amount_tendered' => $tender['amount_tendered'] ?? null,
                'change_given' => $tender['change_given'] ?? null,
            ], $snapshot['tenders'] ?? []),
            // Empty on a receipt issued before receipts carried their lines.
            'lines' => $snapshot['lines'] ?? [],
        ];
    }

    /**
     * The receipts a centre has issued, newest first.
     *
     * Searched by receipt number, request reference or patient name; the
     * payer's name lives only in the frozen snapshot, which is not searched.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return PaymentReceipt::query()
            ->where('issuing_facility_id', $facilityId)
            ->with(['payment.billing:id,request_id', 'issuingFacility'])
            ->when(($filters['status'] ?? null) === 'valid', fn (Builder $query) => $query->active())
            ->when(($filters['status'] ?? null) === 'voided', fn (Builder $query) => $query->whereNotNull('voided_at'))
            ->when(isset($filters['from']), function (Builder $query) use ($filters): void {
                $query->where('issued_at', '>=', OperationalDay::boundsFor($filters['from'])[0]);
            })
            ->when(isset($filters['to']), function (Builder $query) use ($filters): void {
                $query->where('issued_at', '<=', OperationalDay::boundsFor($filters['to'])[1]);
            })
            ->when(isset($filters['search']), function (Builder $query) use ($filters): void {
                $like = '%'.trim($filters['search']).'%';

                $query->where(fn (Builder $match) => $match
                    ->where('receipt_number', 'like', $like)
                    ->orWhereHas('payment.billing.request', fn (Builder $request) => $request
                        ->where('reference_number', 'like', $like)
                        ->orWhere('patient_surname', 'like', $like)
                        ->orWhere('patient_first_name', 'like', $like)));
            })
            ->latest('issued_at')
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (PaymentReceipt $receipt): array => $this->format($receipt));
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
        $this->auditLogger->record($viewer, 'billing.receipt_downloaded', $receipt->payment?->billing, [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'facility_id' => $viewer->facility_id,
        ]);

        return Pdf::loadView('pdf.payment-receipt', $this->viewData($receipt))
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->download("{$receipt->receipt_number}.pdf");
    }

    /**
     * Everything the printed receipt needs, read from its snapshot.
     *
     * @return array<string, mixed>
     */
    public function viewData(PaymentReceipt $receipt): array
    {
        $receipt->loadMissing('issuingFacility');
        $issuer = $receipt->snapshot['issuing_facility'] ?? [];

        // dompdf cannot decode a PNG without GD; the receipt still prints without the logo.
        $images = extension_loaded('gd');

        return [
            'receipt' => $receipt,
            'snapshot' => $receipt->snapshot,
            'logo' => $images ? $this->facilityLogoService->dataUriForPath($receipt->issuingFacility, $issuer['logo_path'] ?? null) : null,
            'monogram' => FacilityMonogram::of($issuer['name'] ?? $receipt->issuingFacility?->name),
        ];
    }

    private function notFound(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'Receipt not found.',
            'code' => 'receipt_not_found',
        ], 404));
    }
}
