{{--
    Payment Acknowledgement Receipt, rendered only from the receipt's snapshot.

    The snapshot was frozen when the payment was recorded, so a later top-up,
    subsidy or correction never changes a receipt already handed over. A
    partial payment shows the balance before and after it, so it can never
    read as settling the whole statement.

    Laid out as the owner's billing mock-up (2026-10-11), like the statement.
    A receipt issued before receipts carried their lines prints without the
    lines table. Not a BIR official receipt: the receipt says so.

    Headed by the issuing centre — the logo frozen in the snapshot, or its
    initials when it has none — never by a shared mark. RedAgos is named only
    in the footer.
--}}
@php
    $peso = fn (string|int|float|null $amount): string => '₱'.number_format((float) ($amount ?? 0), 2);
    $after = (float) ($snapshot['balance_after'] ?? 0);
    $facility = $snapshot['issuing_facility'] ?? [];
    $request = $snapshot['request'] ?? [];
    $statement = $snapshot['statement'] ?? null;
    $payment = $snapshot['payment'] ?? [];
    $lines = $snapshot['lines'] ?? [];
    $partial = ! empty($snapshot['is_partial']);
    $issuedAt = \Illuminate\Support\Carbon::parse($snapshot['issued_at'])->timezone(config('blood_center.timezone'));
    $paidAt = ! empty($payment['paid_at'])
        ? \Illuminate\Support\Carbon::parse($payment['paid_at'])->timezone(config('blood_center.timezone'))
        : $issuedAt;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Acknowledgement Receipt {{ $snapshot['receipt_number'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 12mm 12mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.4; color: #17212b; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .muted { color: #657180; }
        .issuer-logo { max-width: 120px; max-height: 56px; }
        .monogram { width: 52px; height: 52px; background: #2f3b48; color: #fff; border-radius: 12px; text-align: center; font-size: 17pt; font-weight: bold; line-height: 52px; }
        .brand-name { font-size: 15pt; font-weight: bold; }
        .doc-title { text-align: right; }
        .doc-title .name { font-size: 14pt; font-weight: bold; color: #b91f2b; letter-spacing: 0.5px; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 7.5pt; font-weight: bold; }
        .pill-paid { background: #e3f7ed; color: #16845b; }
        .pill-due { background: #fff1f2; color: #b91f2b; }
        .pill-void { background: #eceff2; color: #a40000; }
        .rule { border-bottom: 1px solid #e1e6eb; margin: 12px 0 14px; }
        .boxes td.box { width: 33.33%; background: #f5f7f9; border: 1px solid #e1e6eb; padding: 8px 9px; }
        .boxes td.gap { width: 8px; background: none; border: none; padding: 0; }
        .box h3 { margin: 0 0 5px; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6px; color: #657180; }
        .box strong { display: block; margin-bottom: 2px; }
        .box p { margin: 1px 0; color: #657180; font-size: 7.5pt; }
        .section { font-size: 9pt; font-weight: bold; margin: 16px 0 6px; }
        .lines th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5px; color: #657180; background: #f5f7f9; padding: 6px; border-bottom: 1px solid #e1e6eb; }
        .lines td { padding: 7px 6px; border-bottom: 1px solid #e1e6eb; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 46%; margin: 12px 0 0 54%; }
        .totals td { padding: 5px 8px; border-bottom: 1px solid #e1e6eb; }
        .totals .grand td { font-size: 10.5pt; font-weight: bold; background: #f5f7f9; border-bottom: none; }
        .totals .balance td { font-weight: bold; color: #b91f2b; background: #fff1f2; border-bottom: none; }
        .totals .settled td { font-weight: bold; color: #16845b; background: #e3f7ed; border-bottom: none; }
        .panel { margin-top: 14px; }
        .panel td.pbox { border: 1px solid #e1e6eb; padding: 9px 10px; }
        .panel td.gap { width: 8px; border: none; padding: 0; }
        .panel h3 { margin: 0 0 6px; font-size: 8pt; }
        .kv td { padding: 2px 0; font-size: 7.5pt; }
        .kv td.k { color: #657180; }
        .kv td.v { text-align: right; font-weight: bold; }
        .callout { background: #eefaf4; }
        .callout.partial { background: #fff8eb; }
        .callout strong { color: #16845b; font-size: 10pt; }
        .callout.partial strong { color: #9b6200; }
        .callout p { margin: 5px 0 0; color: #657180; font-size: 7.5pt; }
        .notes { margin-top: 14px; padding: 8px 10px; background: #f5f7f9; border: 1px dashed #c9d1d9; color: #657180; font-size: 7.5pt; }
        .notes p { margin: 3px 0 0; }
        .sign td { padding-top: 26px; font-size: 7.5pt; color: #657180; }
        .footer { margin-top: 18px; background: #b91f2b; color: #fff; padding: 7px 10px; font-size: 7pt; }
        .footer td { color: #fff; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: {{ $logo ? 132 : 64 }}px;">
                @if ($logo)
                    <img class="issuer-logo" src="{{ $logo }}" alt="">
                @else
                    <div class="monogram">{{ $monogram }}</div>
                @endif
            </td>
            <td>
                <div class="brand-name">{{ $facility['name'] ?? '' }}</div>
                @if (! empty($facility['address']))
                    <div class="muted">{{ $facility['address'] }}</div>
                @endif
                <div class="muted">Blood service facility</div>
            </td>
            <td class="doc-title">
                <div class="name">PAYMENT ACKNOWLEDGEMENT RECEIPT</div>
                <div class="muted">Receipt No. {{ $snapshot['receipt_number'] ?? '' }}</div>
                <div class="muted">Date issued: {{ $issuedAt->format('j M Y, g:i A') }}</div>
                <div style="margin-top: 4px;">
                    @if ($receipt->isVoided())
                        <span class="pill pill-void">VOID — {{ $receipt->void_reason }}</span>
                    @elseif ($partial)
                        <span class="pill pill-due">PARTIAL PAYMENT</span>
                    @else
                        <span class="pill pill-paid">PAYMENT RECEIVED</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="boxes">
        <tr>
            <td class="box">
                <h3>Issued by</h3>
                <strong>{{ $facility['name'] ?? '' }}</strong>
                @if (! empty($facility['address']))
                    <p>{{ $facility['address'] }}</p>
                @endif
                @if (! empty($facility['doh_license_number']))
                    <p>DOH licence: {{ $facility['doh_license_number'] }}</p>
                @endif
                @if (! empty($facility['phone']) || ! empty($facility['email']))
                    <p>{{ implode(' · ', array_filter([$facility['phone'] ?? null, $facility['email'] ?? null])) }}</p>
                @endif
            </td>
            <td class="gap"></td>
            <td class="box">
                <h3>Received from</h3>
                <strong>{{ $snapshot['payer_name'] ?? '—' }}</strong>
                @if (! empty($request['patient_name']))
                    <p>For patient: {{ $request['patient_name'] }}</p>
                @endif
                @if (! empty($request['requesting_facility']))
                    <p>c/o {{ $request['requesting_facility'] }}</p>
                @endif
            </td>
            <td class="gap"></td>
            <td class="box">
                <h3>Transaction details</h3>
                <p>Blood request: <strong style="display: inline;">{{ $request['reference_number'] ?? '' }}</strong></p>
                @if ($statement)
                    <p>Statement: {{ $statement['document_number'] }} (revision {{ $statement['revision_number'] }})</p>
                @endif
                @if (! empty($snapshot['replaces_receipt_number']))
                    <p>Replaces receipt: {{ $snapshot['replaces_receipt_number'] }}</p>
                @endif
                <p>Currency: PHP (₱)</p>
            </td>
        </tr>
    </table>

    @if (count($lines) > 0)
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
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $line['component_name'] }}</strong></td>
                        <td class="num">{{ $line['quantity'] }}</td>
                        <td class="num">{{ $peso($line['unit_price']) }}</td>
                        <td class="num">{{ $peso($line['line_total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        @if ($statement)
            <tr>
                <td>Statement total</td>
                <td class="num">{{ $peso($statement['total_amount']) }}</td>
            </tr>
        @endif
        <tr>
            <td>Balance before this payment</td>
            <td class="num">{{ $peso($snapshot['balance_before'] ?? null) }}</td>
        </tr>
        <tr class="grand">
            <td>Amount received</td>
            <td class="num">{{ $peso($snapshot['amount_paid'] ?? null) }}</td>
        </tr>
        @if ($after < 0)
            <tr class="balance">
                <td>Received in excess of the balance</td>
                <td class="num">{{ $peso(abs($after)) }}</td>
            </tr>
        @elseif ($after > 0)
            <tr class="balance">
                <td>Remaining balance</td>
                <td class="num">{{ $peso($after) }}</td>
            </tr>
        @else
            <tr class="settled">
                <td>Remaining balance</td>
                <td class="num">{{ $peso(0) }}</td>
            </tr>
        @endif
    </table>

    <table class="panel">
        <tr>
            <td class="pbox" style="width: 58%;">
                <h3>Payment received</h3>
                <table class="kv">
                    <tr><td class="k">Payment method</td><td class="v">{{ $payment['method_label'] ?? '' }}</td></tr>
                    <tr><td class="k">Amount received</td><td class="v">{{ $peso($snapshot['amount_paid'] ?? null) }}</td></tr>
                    @if (! empty($payment['amount_tendered']))
                        <tr><td class="k">Cash tendered</td><td class="v">{{ $peso($payment['amount_tendered']) }}</td></tr>
                        <tr><td class="k">Change given</td><td class="v">{{ $peso($payment['change_given'] ?? 0) }}</td></tr>
                    @endif
                    @if (! empty($payment['cash_session_number']))
                        <tr><td class="k">Counter shift</td><td class="v">{{ $payment['cash_session_number'] }}</td></tr>
                    @endif
                    <tr><td class="k">Payment reference</td><td class="v">{{ $payment['reference_number'] ?? '—' }}</td></tr>
                    <tr><td class="k">Payment date</td><td class="v">{{ $paidAt->format('j M Y, g:i A') }}</td></tr>
                    <tr><td class="k">Received by</td><td class="v">{{ $snapshot['received_by'] ?? 'Confirmed by the payment provider' }}</td></tr>
                </table>
            </td>
            <td class="gap"></td>
            <td class="pbox callout {{ $partial ? 'partial' : '' }}">
                <h3>{{ $partial ? 'Part payment recorded' : 'Payment recorded' }}</h3>
                <strong>{{ $peso($snapshot['amount_paid'] ?? null) }} received</strong>
                <p>
                    @if ($partial)
                        This acknowledges the amount above only. {{ $peso($after) }} is still due.
                    @else
                        The statement is settled by this payment.
                    @endif
                </p>
            </td>
        </tr>
    </table>

    <div class="notes">
        <strong>Notes</strong>
        <p>This acknowledges payment received. It is not a BIR official receipt.</p>
        <table class="sign">
            <tr>
                <td>Received by: {{ $snapshot['received_by'] ?? '______________________' }}</td>
                <td>Authorized by: ______________________</td>
            </tr>
        </table>
    </div>

    <table class="footer">
        <tr>
            <td>{{ $facility['name'] ?? '' }} &middot; Payment Acknowledgement Receipt</td>
            <td style="text-align: center;">Keep this receipt for reference &middot; Generated by RedAgos</td>
            <td style="text-align: right;">{{ $snapshot['receipt_number'] ?? '' }}</td>
        </tr>
    </table>
</body>
</html>
