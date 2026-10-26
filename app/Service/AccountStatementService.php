<?php

namespace App\Service;

use App\Enums\RequestPurpose;
use App\Enums\TransactionCategory;
use App\Models\BillingRevision;
use App\Models\BillingTransaction;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Support\FacilityMonogram;
use App\Support\Money;
use App\Support\OperationalDay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A hospital's Statement of Account: its invoices, its payments and what it still owes, over a period.
 *
 * The project owner's terms (2026-10-25): an invoice is one billable request;
 * a Statement of Account summarises a facility's invoices, payments and
 * remaining balance over a period; a receipt is proof of a payment received.
 *
 * Only what the hospital itself owes goes on it: its weekly replenishment
 * invoices and the settlements it made. A Patient Transfusion invoice is the
 * patient's or watcher's to pay (project owner, 2026-10-10), so it stays off.
 *
 * Generated on demand from the billing journal and never stored or numbered
 * (owner's choice, 2026-10-25). Journal rows are never changed, so the same
 * period adds up the same way every time. Scoped to the caller's own centre.
 */
class AccountStatementService
{
    public function __construct(
        private readonly FacilityLogoService $facilityLogoService
    ) {}

    /**
     * The hospitals this centre has invoiced for weekly replenishment, by name.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function hospitals(User $staff): array
    {
        $centreId = $this->requireFacilityId($staff);

        return Facility::query()
            ->whereIn('id', BloodRequest::query()
                ->addressedTo($centreId)
                ->where('request_purpose', RequestPurpose::Replenishment->value)
                ->whereHas('billing')
                ->select('facility_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Facility $facility): array => ['id' => $facility->id, 'name' => $facility->name])
            ->all();
    }

    /**
     * One hospital's statement for a period of operational (Manila) days.
     *
     * @return array<string, mixed>
     */
    public function build(User $staff, int $hospitalId, string $from, string $to): array
    {
        $centreId = $this->requireFacilityId($staff);
        $staff->loadMissing('facility');

        $hospital = Facility::query()->find($hospitalId)
            ?? throw $this->refuse(404, 'facility_missing', 'That hospital could not be found.');

        [$start] = OperationalDay::boundsFor($from);
        [, $end] = OperationalDay::boundsFor($to);

        // Everything on the hospital's own invoices up to the period's end.
        $rows = BillingTransaction::query()
            ->where('facility_id', $centreId)
            ->where('category', TransactionCategory::WeeklyReplenishment->value)
            ->whereHas('request', fn (Builder $request) => $request->where('facility_id', $hospital->id))
            ->where('occurred_at', '<=', $end)
            ->with([
                'request:id,reference_number',
                'billing:id,request_id,billing_date,status,settled_at,settlement_reference',
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'transaction_number', 'billing_id', 'request_id', 'type', 'amount', 'reference', 'occurred_at']);

        [$before, $within] = $rows->partition(fn (BillingTransaction $row): bool => $row->occurred_at->lessThan($start));

        $invoiceNumbers = $this->invoiceNumbersAsOf($rows->pluck('billing_id')->unique()->all(), $end);

        $opening = $this->sumOf($before);
        $invoiced = (int) $within->sum(fn (BillingTransaction $row): int => max(Money::toCentavos($row->amount), 0));
        $payments = (int) $within->sum(fn (BillingTransaction $row): int => max(-Money::toCentavos($row->amount), 0));

        $running = $opening;
        $activity = $within->values()->map(function (BillingTransaction $row) use (&$running, $invoiceNumbers): array {
            $amount = Money::toCentavos($row->amount);
            $running += $amount;

            return [
                'occurred_at' => $row->occurred_at->toIso8601String(),
                'transaction_number' => $row->transaction_number,
                'type' => $row->type->value,
                'type_label' => $row->type->label(),
                'invoice_number' => $invoiceNumbers[$row->billing_id] ?? null,
                'request_reference' => $row->request?->reference_number,
                'reference' => $row->reference,
                'charge' => $amount > 0 ? Money::toDecimal($amount) : null,
                'payment' => $amount < 0 ? Money::toDecimal(-$amount) : null,
                'balance' => Money::toDecimal($running),
            ];
        })->all();

        return [
            'issuer' => [
                'name' => $staff->facility?->name,
                'address' => $staff->facility?->address,
                'logo_url' => $this->facilityLogoService->urlFor($staff->facility),
            ],
            'hospital' => [
                'id' => $hospital->id,
                'name' => $hospital->name,
                'address' => $hospital->address,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'opening_balance' => Money::toDecimal($opening),
            'invoiced' => Money::toDecimal($invoiced),
            'payments' => Money::toDecimal($payments),
            'closing_balance' => Money::toDecimal($opening + $invoiced - $payments),
            'invoices' => $this->invoices($rows, $within, $invoiceNumbers),
            'activity' => $activity,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The same statement as a printable A4 PDF, headed by the issuing centre.
     */
    public function pdf(User $staff, int $hospitalId, string $from, string $to): Response
    {
        $statement = $this->build($staff, $hospitalId, $from, $to);

        // dompdf cannot decode a PNG without GD; the statement still prints without the logo.
        $logo = extension_loaded('gd') ? $this->facilityLogoService->dataUriFor($staff->facility) : null;

        return Pdf::loadView('pdf.account-statement', [
            'statement' => $statement,
            'facility' => $staff->facility,
            'logo' => $logo,
            'monogram' => FacilityMonogram::of($staff->facility?->name),
        ])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->download('statement-of-account-'.Str::slug((string) $statement['hospital']['name']).'-'.$from.'-to-'.$to.'.pdf');
    }

    /**
     * Each invoice the period touched, or still open at its end: what it billed, what was settled and what is left.
     *
     * @param  Collection<int, BillingTransaction>  $rows  every row up to the period's end
     * @param  Collection<int, BillingTransaction>  $within  the period's rows
     * @param  array<int, string>  $invoiceNumbers
     * @return array<int, array<string, mixed>>
     */
    private function invoices(Collection $rows, Collection $within, array $invoiceNumbers): array
    {
        $touched = $within->pluck('billing_id')->unique()->all();

        return $rows->groupBy('billing_id')
            ->map(function (Collection $billingRows, int $billingId) use ($touched, $invoiceNumbers): ?array {
                $billed = (int) $billingRows->sum(fn (BillingTransaction $row): int => max(Money::toCentavos($row->amount), 0));
                $settled = (int) $billingRows->sum(fn (BillingTransaction $row): int => max(-Money::toCentavos($row->amount), 0));
                $balance = $billed - $settled;

                if ($balance === 0 && ! in_array($billingId, $touched, true)) {
                    return null;
                }

                $first = $billingRows->first();

                return [
                    'billing_id' => $billingId,
                    'request_id' => $first->request_id,
                    'invoice_number' => $invoiceNumbers[$billingId] ?? null,
                    'request_reference' => $first->request?->reference_number,
                    'billed_on' => $first->billing?->billing_date?->toIso8601String(),
                    'amount' => Money::toDecimal($billed),
                    'settled' => Money::toDecimal($settled),
                    'balance' => Money::toDecimal($balance),
                    'settlement_reference' => $first->billing?->settlement_reference,
                ];
            })
            ->filter()
            ->sortBy('billed_on')
            ->values()
            ->all();
    }

    /**
     * Each invoice's number as the hospital last received it by the period's end.
     *
     * @param  array<int, int>  $billingIds
     * @return array<int, string>
     */
    private function invoiceNumbersAsOf(array $billingIds, mixed $end): array
    {
        return BillingRevision::query()
            ->whereIn('billing_id', $billingIds)
            ->where('created_at', '<=', $end)
            ->orderBy('revision_number')
            ->get(['billing_id', 'document_number'])
            ->groupBy('billing_id')
            ->map(fn (Collection $revisions): string => (string) $revisions->last()->document_number)
            ->all();
    }

    /**
     * @param  Collection<int, BillingTransaction>  $rows
     */
    private function sumOf(Collection $rows): int
    {
        return (int) $rows->sum(fn (BillingTransaction $row): int => Money::toCentavos($row->amount));
    }

    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
