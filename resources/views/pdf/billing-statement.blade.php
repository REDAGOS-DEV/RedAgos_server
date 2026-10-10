{{--
    Statement of Account: one frozen revision of a statement.

    Rendered only from the revision's own rows, never the live statement, so
    the same revision always prints the same document. Tables only and
    absolute sizes, because dompdf supports neither flex nor grid. The logo
    arrives as a data URI (remote fetching is off) and may be absent.

    Laid out as the owner's billing mock-up (2026-10-11): issuer, bill-to and
    transaction boxes, the lines, the totals, notes, and a footer band. It is
    titled a Statement of Account, not an invoice: it is not a BIR document.
--}}
@php
    use App\Enums\BillingStatus;

    $peso = fn (string|int|float|null $amount): string => '₱'.number_format((float) ($amount ?? 0), 2);
    $status = $revision->billing_status;
    $facility = $revision->issuingFacility;
    $subsidised = $status === BillingStatus::Subsidised;
    // A subsidy waives the whole charge; anything collected before it stays
    // recorded and is refunded, if at all, outside RedAgos.
    $subsidy = $subsidised ? (float) $revision->total_amount : 0.0;
    $collected = (float) $revision->collected_at_issue;
    $issuedBy = $revision->creator ? trim($revision->creator->first_name.' '.$revision->creator->last_name) : null;
    $tone = match ($status) {
        BillingStatus::Paid => 'paid',
        BillingStatus::Subsidised, BillingStatus::StatementOnly => 'info',
        BillingStatus::Void => 'void',
        default => 'due',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account {{ $revision->document_number }}</title>
    <style>
        @page { size: A4; margin: 12mm 12mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.4; color: #17212b; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .muted { color: #657180; }
        .mark { width: 34px; height: 34px; background: #b91f2b; color: #fff; border-radius: 9px; text-align: center; font-size: 20pt; font-weight: bold; line-height: 34px; }
        .brand-name { font-size: 15pt; font-weight: bold; }
        .doc-title { text-align: right; }
        .doc-title .name { font-size: 15pt; font-weight: bold; color: #b91f2b; letter-spacing: 0.5px; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 7.5pt; font-weight: bold; }
        .pill-due { background: #fff1f2; color: #b91f2b; }
        .pill-paid { background: #e3f7ed; color: #16845b; }
        .pill-info { background: #eaf0ff; color: #365cb4; }
        .pill-void { background: #eceff2; color: #657180; }
        .rule { border-bottom: 1px solid #e1e6eb; margin: 12px 0 14px; }
        .boxes td.box { width: 33.33%; background: #f5f7f9; border: 1px solid #e1e6eb; padding: 8px 9px; }
        .boxes td.gap { width: 8px; background: none; border: none; padding: 0; }
        .box h3 { margin: 0 0 5px; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6px; color: #657180; }
        .box strong { display: block; margin-bottom: 2px; }
        .box p { margin: 1px 0; color: #657180; font-size: 7.5pt; }
        .box img { max-width: 34px; max-height: 34px; margin-bottom: 4px; }
        .section { font-size: 9pt; font-weight: bold; margin: 16px 0 6px; }
        .lines th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5px; color: #657180; background: #f5f7f9; padding: 6px; border-bottom: 1px solid #e1e6eb; }
        .lines td { padding: 7px 6px; border-bottom: 1px solid #e1e6eb; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 46%; margin: 12px 0 0 54%; }
        .totals td { padding: 5px 8px; border-bottom: 1px solid #e1e6eb; }
        .totals .less td { color: #16845b; }
        .totals .grand td { font-size: 10.5pt; font-weight: bold; background: #f5f7f9; border-bottom: none; }
        .totals .balance td { font-weight: bold; color: #b91f2b; background: #fff1f2; border-bottom: none; }
        .notes { margin-top: 16px; padding: 8px 10px; background: #f5f7f9; border: 1px dashed #c9d1d9; color: #657180; font-size: 7.5pt; }
        .notes p { margin: 3px 0 0; }
        .notes .status-note { color: #17212b; }
        .sign td { padding-top: 26px; font-size: 7.5pt; color: #657180; }
        .footer { margin-top: 18px; background: #b91f2b; color: #fff; padding: 7px 10px; font-size: 7pt; }
        .footer td { color: #fff; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 44px;"><div class="mark">+</div></td>
            <td>
                <div class="brand-name">RedAgos</div>
                <div class="muted">Blood Bank Management &amp; Inventory System</div>
                <div class="muted">{{ $facility?->name }}</div>
            </td>
            <td class="doc-title">
                <div class="name">STATEMENT OF ACCOUNT</div>
                <div class="muted">No. {{ $revision->document_number }} &middot; Revision {{ $revision->revision_number }}</div>
                <div class="muted">Date issued: {{ $revision->created_at?->timezone(config('blood_center.timezone'))->format('j M Y, g:i A') }}</div>
                <div style="margin-top: 4px;"><span class="pill pill-{{ $tone }}">{{ mb_strtoupper($status->label()) }}</span></div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="boxes">
        <tr>
            <td class="box">
                <h3>Issued by</h3>
                @if ($logo)
                    <img src="{{ $logo }}" alt="">
                @endif
                <strong>{{ $facility?->name }}</strong>
                @if ($facility?->address)
                    <p>{{ $facility->address }}</p>
                @endif
                @if ($facility?->doh_license_number)
                    <p>DOH licence: {{ $facility->doh_license_number }}</p>
                @endif
                @if ($facility?->phone || $facility?->email)
                    <p>{{ implode(' · ', array_filter([$facility?->phone, $facility?->email])) }}</p>
                @endif
            </td>
            <td class="gap"></td>
            <td class="box">
                @if ($revision->statement_only)
                    <h3>Bill to</h3>
                    <strong>{{ $revision->payerFacility?->name }}</strong>
                    <p>Requesting hospital</p>
                @else
                    <h3>Bill to / Patient</h3>
                    <strong>{{ $patient ?? 'Patient' }}</strong>
                    <p>c/o {{ $revision->payerFacility?->name }}</p>
                    <p>Payable by the patient or their watcher</p>
                @endif
            </td>
            <td class="gap"></td>
            <td class="box">
                <h3>Transaction details</h3>
                <p>Blood request: <strong style="display: inline;">{{ $request?->reference_number }}</strong></p>
                <p>Request type: {{ $revision->statement_only ? 'Weekly replenishment' : 'Patient transfusion' }}</p>
                <p>
                    Payable:
                    {{ $revision->statement_only ? 'Settled outside RedAgos' : 'Before the units are released' }}
                </p>
                <p>Currency: PHP (₱)</p>
            </td>
        </tr>
    </table>

    <div class="section">Blood components</div>
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th>Component</th>
                <th class="num" style="width: 10%;">Qty</th>
                <th class="num" style="width: 18%;">Unit price</th>
                <th class="num" style="width: 18%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($revision->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td><strong>{{ $item->component_name }}</strong></td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $peso($item->unit_price) }}</td>
                    <td class="num">{{ $peso($item->line_total) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="muted">No units are held for this request.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ $peso($revision->total_amount) }}</td>
        </tr>
        @if ($subsidy > 0)
            <tr class="less">
                <td>Government subsidy</td>
                <td class="num">− {{ $peso($subsidy) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>{{ $revision->statement_only ? 'Amount billed' : 'Amount payable' }}</td>
            <td class="num">{{ $peso((float) $revision->total_amount - $subsidy) }}</td>
        </tr>
        @if ($collected > 0 && ! $subsidised)
            <tr>
                <td>Amount already paid</td>
                <td class="num">{{ $peso($collected) }}</td>
            </tr>
            <tr class="balance">
                <td>Outstanding balance</td>
                <td class="num">{{ $peso($revision->amount_due) }}</td>
            </tr>
        @endif
    </table>

    <div class="notes">
        <strong>Notes</strong>
        @if ($status === BillingStatus::StatementOnly)
            <p class="status-note">
                Weekly replenishment order. This statement goes to the requesting hospital for settlement
                outside RedAgos; no payment is collected against it in this system.
            </p>
        @elseif ($subsidised)
            <p class="status-note">
                Covered by the government subsidy. Nothing is payable on this request.
                @if ($collected > 0)
                    {{ $peso($collected) }} was received before the subsidy; any refund is settled outside RedAgos.
                @endif
            </p>
        @elseif ($status === BillingStatus::Void)
            <p class="status-note">This statement has been voided.</p>
        @elseif ($collected > 0)
            <p class="status-note">Payments received before this statement are deducted above.</p>
        @endif
        <p>
            This Statement of Account is not an invoice and not an official receipt. Payment is acknowledged
            by a separate Payment Acknowledgement Receipt. The figures are those frozen when this revision
            was issued.
        </p>
        <table class="sign">
            <tr>
                <td>Prepared by: {{ $issuedBy ?? '______________________' }}</td>
                <td>Authorized by: ______________________</td>
            </tr>
        </table>
    </div>

    <table class="footer">
        <tr>
            <td>RedAgos &middot; Statement of Account</td>
            <td style="text-align: center;">Keep this document for reference</td>
            <td style="text-align: right;">{{ $revision->document_number }}</td>
        </tr>
    </table>
</body>
</html>
