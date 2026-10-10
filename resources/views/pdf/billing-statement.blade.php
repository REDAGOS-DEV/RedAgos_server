{{--
    Statement of Account: one frozen revision of a statement.

    Rendered only from the revision's own rows, never the live statement, so
    the same revision always prints the same document. Tables only and
    absolute sizes, because dompdf supports neither flex nor grid. The logo
    arrives as a data URI (remote fetching is off) and may be absent.

    Not an invoice and not an official receipt: the footer says so, pending
    finance confirmation of the legal requirements.
--}}
@php
    use App\Enums\BillingStatus;

    $peso = fn (?string $amount): string => '₱'.number_format((float) ($amount ?? 0), 2);
    $status = $revision->billing_status;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account {{ $revision->document_number }}</title>
    <style>
        @page { size: A4; margin: 14mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; line-height: 1.35; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        .head td { vertical-align: middle; }
        .head .logo { width: 70px; }
        .head img { max-width: 62px; max-height: 62px; }
        .facility { font-size: 11pt; font-weight: bold; text-transform: uppercase; }
        .muted { color: #555; }
        .title { font-size: 14pt; font-weight: bold; margin: 14px 0 2px; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .meta .label { width: 34%; color: #555; }
        .lines { margin-top: 14px; }
        .lines th { text-align: left; font-size: 8pt; text-transform: uppercase; border-bottom: 1px solid #333; padding: 5px 4px; }
        .lines td { padding: 5px 4px; border-bottom: 1px solid #ddd; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 10px; width: 55%; margin-left: 45%; }
        .totals td { padding: 3px 4px; }
        .totals .due td { font-weight: bold; font-size: 11pt; border-top: 1px solid #333; }
        .note { margin-top: 14px; padding: 8px 10px; border: 1px solid #999; font-size: 8.5pt; }
        .footer { margin-top: 26px; font-size: 7.5pt; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td class="logo">
                @if ($logo)
                    <img src="{{ $logo }}" alt="">
                @endif
            </td>
            <td>
                <div class="facility">{{ $revision->issuingFacility?->name }}</div>
                @if ($revision->issuingFacility?->address)
                    <div class="muted">{{ $revision->issuingFacility->address }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="title">STATEMENT OF ACCOUNT</div>
    <div class="muted">{{ $revision->document_number }} &middot; Revision {{ $revision->revision_number }}</div>

    <table class="meta" style="margin-top: 10px;">
        <tr>
            <td class="label">Issued</td>
            <td>{{ $revision->created_at?->timezone(config('blood_center.timezone'))->format('j F Y, g:i A') }}</td>
        </tr>
        <tr>
            <td class="label">Blood request</td>
            <td>{{ $request?->reference_number }}</td>
        </tr>
        <tr>
            <td class="label">Requesting facility</td>
            <td>{{ $revision->payerFacility?->name }}</td>
        </tr>
        @if ($patient)
            <tr>
                <td class="label">Patient</td>
                <td>{{ $patient }}</td>
            </tr>
        @endif
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Component</th>
                <th class="num">Units</th>
                <th class="num">Unit price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($revision->items as $item)
                <tr>
                    <td>{{ $item->component_name }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $peso($item->unit_price) }}</td>
                    <td class="num">{{ $peso($item->line_total) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted">No units are held for this request.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Total</td>
            <td class="num">{{ $peso($revision->total_amount) }}</td>
        </tr>
        @if ((float) $revision->collected_at_issue > 0)
            <tr>
                <td>Received before this statement</td>
                <td class="num">− {{ $peso($revision->collected_at_issue) }}</td>
            </tr>
        @endif
        <tr class="due">
            <td>Amount due</td>
            <td class="num">{{ $peso($revision->amount_due) }}</td>
        </tr>
    </table>

    @if ($status === BillingStatus::StatementOnly)
        <div class="note">
            Weekly replenishment order. This statement is issued to the requesting facility for settlement
            outside RedAgos; no payment is collected against it in this system.
        </div>
    @elseif ($status === BillingStatus::Subsidised)
        <div class="note">Covered by the government subsidy. Nothing is payable on this request.</div>
    @elseif ($status === BillingStatus::Void)
        <div class="note">This statement has been voided.</div>
    @endif

    <div class="footer">
        This Statement of Account is not an invoice and not an official receipt. Payment is acknowledged
        by a separate Payment Acknowledgement Receipt. Printed from RedAgos; the figures are those frozen
        when this revision was issued.
    </div>
</body>
</html>
