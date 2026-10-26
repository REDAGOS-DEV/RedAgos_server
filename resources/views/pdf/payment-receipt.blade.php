{{--
    Payment Acknowledgement Receipt, rendered only from the receipt's snapshot.

    The snapshot was frozen when the payment was recorded, so a later top-up,
    subsidy or correction never changes a receipt already handed over. A
    partial payment shows the balance before and after it, so it can never
    read as settling the whole statement.

    Redesigned 2026-10-14: the amount received is stated once, large, beside
    how and when it was paid; the lines and the balance follow. Blue carries
    the document, with red only as the thin brand edge at the top. A receipt
    issued before receipts carried their lines prints without the lines table.
    Not a BIR official receipt: the receipt says so.

    Headed by the issuing centre — the logo frozen in the snapshot, or its
    initials when it has none — never by a shared mark. RedAgos is named only
    in the footer. Laid out with tables, since dompdf has no flexbox.
--}}
@php
    $peso = fn (string|int|float|null $amount): string => '₱'.number_format((float) ($amount ?? 0), 2);
    $after = (float) ($snapshot['balance_after'] ?? 0);
    $facility = $snapshot['issuing_facility'] ?? [];
    $request = $snapshot['request'] ?? [];
    $statement = $snapshot['statement'] ?? null;
    $payment = $snapshot['payment'] ?? [];
    // Each part of a payment split across methods. One receipt lists them all.
    $tenders = $snapshot['tenders'] ?? [];
    $split = count($tenders) > 1;
    $lines = $snapshot['lines'] ?? [];
    $partial = ! empty($snapshot['is_partial']);
    $voided = $receipt->isVoided();
    $issuedAt = \Illuminate\Support\Carbon::parse($snapshot['issued_at'])->timezone(config('blood_center.timezone'));
    $paidAt = ! empty($payment['paid_at'])
        ? \Illuminate\Support\Carbon::parse($payment['paid_at'])->timezone(config('blood_center.timezone'))
        : $issuedAt;
    $contact = implode('  ·  ', array_filter([
        ! empty($facility['doh_license_number']) ? 'DOH licence '.$facility['doh_license_number'] : null,
        $facility['phone'] ?? null,
        $facility['email'] ?? null,
    ]));
    if ($voided) {
        $status = ['label' => 'VOID', 'class' => 'pill-void'];
    } elseif ($partial) {
        $status = ['label' => 'PARTIAL PAYMENT', 'class' => 'pill-due'];
    } else {
        $status = ['label' => 'PAID IN FULL', 'class' => 'pill-paid'];
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Acknowledgement Receipt {{ $snapshot['receipt_number'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 0 0 14mm; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.4; color: #17212b; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }
        .page { padding: 0 13mm; }
        .muted { color: #657180; }
        .mono { font-family: "DejaVu Sans Mono", monospace; }
        .num { text-align: right; white-space: nowrap; }

        /* Brand edge: red is only this thin line. */
        .edge { height: 4px; background: #c62828; }
        .edge-blue { height: 2px; background: #1565c0; margin-bottom: 9mm; }

        .brand-mark { vertical-align: middle; }
        .brand-mark-logo { width: 134px; }
        .brand-mark-initials { width: 62px; }
        .issuer-logo { max-width: 120px; max-height: 54px; }
        .monogram td { width: 50px; height: 50px; background: #1565c0; color: #fff; border-radius: 12px; text-align: center; vertical-align: middle; font-size: 15pt; font-weight: bold; }
        .brand-name { font-size: 14pt; font-weight: bold; line-height: 1.2; }
        .brand-sub { margin-top: 2px; font-size: 7.5pt; color: #657180; }

        .doc-title { text-align: right; }
        .doc-kicker { font-size: 7pt; font-weight: bold; letter-spacing: 1.2px; color: #1565c0; text-transform: uppercase; }
        .doc-no { margin-top: 3px; font-size: 11pt; font-weight: bold; }
        .doc-date { margin-top: 1px; font-size: 7.5pt; color: #657180; }

        .void-banner { margin-top: 7mm; padding: 7px 10px; border: 1px solid #f1c2c2; background: #fdf1f1; color: #a42020; font-size: 8pt; border-radius: 6px; }

        /* The one number: what was received. */
        .hero { margin-top: 7mm; background: #f2f6fc; border: 1px solid #dbe5f3; border-radius: 10px; }
        .hero td.hero-main { padding: 14px 16px; width: 48%; border-right: 1px solid #dbe5f3; }
        .hero td.hero-side { padding: 12px 16px; }
        .hero-label { font-size: 7pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #657180; }
        .hero-amount { margin-top: 3px; font-size: 22pt; font-weight: bold; color: #0d3c7a; line-height: 1.15; }
        .hero-amount.void { color: #8a94a1; text-decoration: line-through; }
        .pill { display: inline-block; margin-top: 7px; padding: 2px 8px; border-radius: 9px; font-size: 7pt; font-weight: bold; letter-spacing: 0.4px; }
        .pill-paid { background: #dff4e9; color: #126a49; }
        .pill-due { background: #fdf0d9; color: #8a5600; }
        .pill-void { background: #e9ecef; color: #4b5563; }
        .kv td { padding: 2.5px 0; font-size: 8pt; }
        .kv td.k { color: #657180; width: 42%; }
        .kv td.v { text-align: right; font-weight: bold; }

        /* Who and what for */
        .parties { margin-top: 6mm; }
        .parties td.cell { width: 33.33%; padding-right: 14px; }
        .parties td.cell + td.cell { padding-left: 14px; border-left: 1px solid #e1e6eb; }
        .cap { font-size: 6.8pt; font-weight: bold; letter-spacing: 0.9px; text-transform: uppercase; color: #657180; margin-bottom: 3px; }
        .parties strong { display: block; font-size: 9pt; }
        .parties p { margin: 1px 0 0; font-size: 7.6pt; color: #657180; }

        .section { margin: 7mm 0 2mm; font-size: 9pt; font-weight: bold; }
        .lines th { text-align: left; font-size: 6.8pt; text-transform: uppercase; letter-spacing: 0.7px; color: #657180; padding: 6px 8px; border-bottom: 1.5px solid #17212b; }
        .lines th.num { text-align: right; }
        .lines td { padding: 7px 8px; border-bottom: 1px solid #e6eaef; }
        .lines td.idx { color: #8a94a1; width: 5%; }

        /* Balance beside the cash details */
        .summary { margin-top: 6mm; }
        .summary td.left { width: 50%; padding-right: 18px; }
        .summary td.right { width: 50%; }
        .totals td { padding: 5px 8px; border-bottom: 1px solid #e6eaef; font-size: 8.2pt; }
        .totals tr.this td { font-weight: bold; color: #0d3c7a; }
        .totals tr.end td { font-weight: bold; border-bottom: none; }
        .totals tr.end.due td { background: #fdf0d9; color: #8a5600; }
        .totals tr.end.settled td { background: #dff4e9; color: #126a49; }
        .totals tr.end.excess td { background: #e8f0fb; color: #0d3c7a; }
        .cash td { padding: 3px 0; font-size: 8pt; }
        .cash td.k { color: #657180; }
        .cash td.v { text-align: right; font-weight: bold; }
        .cash-box { border: 1px solid #e1e6eb; border-radius: 8px; padding: 8px 10px; }

        .notes { margin-top: 8mm; font-size: 7.5pt; color: #657180; }
        .notes p { margin: 2px 0 0; }
        .notes .lead { color: #17212b; font-weight: bold; }
        .sign { margin-top: 12mm; }
        .sign td { width: 50%; padding-right: 24px; font-size: 7.5pt; color: #657180; }
        .sign .line { border-top: 1px solid #9aa4b0; padding-top: 4px; }
        .sign .who { color: #17212b; font-weight: bold; }

        .footer { position: fixed; bottom: -9mm; left: 13mm; right: 13mm; border-top: 1px solid #e1e6eb; padding-top: 5px; font-size: 6.8pt; color: #8a94a1; }
    </style>
</head>
<body>
    <div class="edge"></div>
    <div class="edge-blue"></div>

    <div class="footer">
        <table>
            <tr>
                <td>{{ $facility['name'] ?? '' }} · Payment Acknowledgement Receipt</td>
                <td style="text-align: center;">Keep this receipt for reference · Generated by RedAgos</td>
                <td class="mono" style="text-align: right;">{{ $snapshot['receipt_number'] ?? '' }}</td>
            </tr>
        </table>
    </div>

    <div class="page">
        {{-- Header: the centre on the left, the document on the right --}}
        <table>
            <tr>
                <td class="brand-mark {{ $logo ? 'brand-mark-logo' : 'brand-mark-initials' }}">
                    @if ($logo)
                        <img class="issuer-logo" src="{{ $logo }}" alt="">
                    @else
                        <table class="monogram" style="width: 50px;"><tr><td>{{ $monogram }}</td></tr></table>
                    @endif
                </td>
                <td style="vertical-align: middle;">
                    <div class="brand-name">{{ $facility['name'] ?? '' }}</div>
                    @if (! empty($facility['address']))
                        <div class="brand-sub">{{ $facility['address'] }}</div>
                    @endif
                    @if ($contact !== '')
                        <div class="brand-sub">{{ $contact }}</div>
                    @endif
                </td>
                <td class="doc-title" style="width: 38%; vertical-align: middle;">
                    <div class="doc-kicker">Payment Acknowledgement Receipt</div>
                    <div class="doc-no mono">{{ $snapshot['receipt_number'] ?? '' }}</div>
                    <div class="doc-date">Issued {{ $issuedAt->format('j M Y, g:i A') }}</div>
                </td>
            </tr>
        </table>

        @if ($voided)
            <div class="void-banner"><strong>This receipt is void.</strong> {{ $receipt->void_reason }}</div>
        @endif

        {{-- The amount received, once, with how and when --}}
        <table class="hero">
            <tr>
                <td class="hero-main">
                    <div class="hero-label">Amount received</div>
                    <div class="hero-amount {{ $voided ? 'void' : '' }}">{{ $peso($snapshot['amount_paid'] ?? null) }}</div>
                    <span class="pill {{ $status['class'] }}">{{ $status['label'] }}</span>
                </td>
                <td class="hero-side">
                    <table class="kv">
                        <tr><td class="k">Payment method</td><td class="v">{{ $payment['method_label'] ?? '' }}</td></tr>
                        @if ($split)
                            @foreach ($tenders as $tender)
                                <tr>
                                    <td class="k">&nbsp;&nbsp;{{ $tender['method_label'] ?? '' }}</td>
                                    <td class="v">{{ $peso($tender['amount_paid'] ?? null) }}@if (! empty($tender['reference_number'])) &middot; Ref. {{ $tender['reference_number'] }}@endif</td>
                                </tr>
                            @endforeach
                        @endif
                        <tr><td class="k">Payment date</td><td class="v">{{ $paidAt->format('j M Y, g:i A') }}</td></tr>
                        @unless ($split)
                            <tr><td class="k">Reference</td><td class="v mono">{{ $payment['reference_number'] ?? '—' }}</td></tr>
                        @endunless
                        <tr><td class="k">Received by</td><td class="v">{{ $snapshot['received_by'] ?? 'Confirmed by the payment provider' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- Who paid, for whom, against what --}}
        <table class="parties">
            <tr>
                <td class="cell">
                    <div class="cap">Received from</div>
                    <strong>{{ $snapshot['payer_name'] ?? '—' }}</strong>
                </td>
                <td class="cell">
                    <div class="cap">For patient</div>
                    <strong>{{ $request['patient_name'] ?? '—' }}</strong>
                    @if (! empty($request['requesting_facility']))
                        <p>c/o {{ $request['requesting_facility'] }}</p>
                    @endif
                </td>
                <td class="cell">
                    <div class="cap">Blood request</div>
                    <strong class="mono">{{ $request['reference_number'] ?? '—' }}</strong>
                    @if ($statement)
                        <p>Statement <span class="mono">{{ $statement['document_number'] }}</span>, rev.&nbsp;{{ $statement['revision_number'] }}</p>
                    @endif
                    @if (! empty($snapshot['replaces_receipt_number']))
                        <p>Replaces receipt <span class="mono">{{ $snapshot['replaces_receipt_number'] }}</span></p>
                    @endif
                </td>
            </tr>
        </table>

        @if (count($lines) > 0)
            <div class="section">Blood components</div>
            <table class="lines">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Component</th>
                        <th class="num" style="width: 9%;">Qty</th>
                        <th class="num" style="width: 18%;">Unit price</th>
                        <th class="num" style="width: 18%;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td class="idx">{{ $loop->iteration }}</td>
                            <td><strong>{{ $line['component_name'] }}</strong></td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td class="num">{{ $peso($line['unit_price']) }}</td>
                            <td class="num">{{ $peso($line['line_total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- Cash details (when cash changed hands) beside the balance --}}
        <table class="summary">
            <tr>
                <td class="left">
                    @if (! empty($payment['amount_tendered']) || ! empty($payment['cash_session_number']))
                        <div class="cap">Cash at the counter</div>
                        <div class="cash-box">
                            <table class="cash">
                                @if (! empty($payment['amount_tendered']))
                                    <tr><td class="k">Cash tendered</td><td class="v">{{ $peso($payment['amount_tendered']) }}</td></tr>
                                    <tr><td class="k">Change given</td><td class="v">{{ $peso($payment['change_given'] ?? 0) }}</td></tr>
                                @endif
                                @if (! empty($payment['cash_session_number']))
                                    <tr><td class="k">Counter shift</td><td class="v mono">{{ $payment['cash_session_number'] }}</td></tr>
                                @endif
                            </table>
                        </div>
                    @endif
                </td>
                <td class="right">
                    <div class="cap">Balance</div>
                    <table class="totals">
                        @if ($statement)
                            <tr><td>Statement total</td><td class="num">{{ $peso($statement['total_amount']) }}</td></tr>
                        @endif
                        <tr><td>Balance before this payment</td><td class="num">{{ $peso($snapshot['balance_before'] ?? null) }}</td></tr>
                        <tr class="this"><td>This payment</td><td class="num">− {{ $peso($snapshot['amount_paid'] ?? null) }}</td></tr>
                        @if ($after < 0)
                            <tr class="end excess"><td>Received in excess of the balance</td><td class="num">{{ $peso(abs($after)) }}</td></tr>
                        @elseif ($after > 0)
                            <tr class="end due"><td>Remaining balance</td><td class="num">{{ $peso($after) }}</td></tr>
                        @else
                            <tr class="end settled"><td>Remaining balance</td><td class="num">{{ $peso(0) }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        <div class="notes">
            @if ($partial && ! $voided)
                <p class="lead">Part payment. This acknowledges the amount above only; {{ $peso($after) }} is still due.</p>
            @elseif (! $voided && $after <= 0)
                <p class="lead">The statement is settled by this payment.</p>
            @endif
            <p>This acknowledges payment received. It is not a BIR official receipt. Amounts are in Philippine pesos (PHP).</p>
        </div>

        <table class="sign">
            <tr>
                <td>
                    <div class="line">
                        <span class="who">{{ $snapshot['received_by'] ?? ' ' }}</span><br>Received by
                    </div>
                </td>
                <td>
                    <div class="line"><span class="who">&nbsp;</span><br>Authorized by</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
