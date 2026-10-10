{{--
    A cash shift's reading: the X reading while the shift is open, the Z
    reading once it is closed and counted.

    Rendered from CashSessionService::report(), whose figures come from the
    shift's own journal rows: the float, cash taken, cash voided, what the
    drawer should hold, and — once closed — what was counted and the
    difference. GCash is shown beside the drawer, never in it.

    Headed by the centre's own logo, or its initials, like every billing
    document. Tables only and absolute sizes, because dompdf supports neither
    flex nor grid.
--}}
@php
    $peso = fn (string|int|float|null $amount): string => ($amount !== null && (float) $amount < 0 ? '−' : '').'₱'.number_format(abs((float) ($amount ?? 0)), 2);
    $figures = $report['figures'];
    $closed = $report['status'] === 'closed';
    $tz = config('blood_center.timezone');
    $when = fn (?string $iso): string => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone($tz)->format('j M Y, g:i A') : '—';
    $variance = (float) ($figures['variance'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Cash Shift Report {{ $report['session_number'] }}</title>
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
        .pill-open { background: #eaf0ff; color: #365cb4; }
        .pill-closed { background: #e3f7ed; color: #16845b; }
        .rule { border-bottom: 1px solid #e1e6eb; margin: 12px 0 14px; }
        .boxes td.box { width: 50%; background: #f5f7f9; border: 1px solid #e1e6eb; padding: 8px 9px; }
        .boxes td.gap { width: 8px; background: none; border: none; padding: 0; }
        .box h3 { margin: 0 0 5px; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6px; color: #657180; }
        .kv td { padding: 2px 0; font-size: 8pt; }
        .kv td.k { color: #657180; }
        .kv td.v { text-align: right; font-weight: bold; }
        .kv tr.total td { border-top: 1px solid #c9d1d9; padding-top: 4px; font-size: 9.5pt; }
        .kv tr.short td.v { color: #b91f2b; }
        .kv tr.over td.v { color: #9b6200; }
        .kv tr.even td.v { color: #16845b; }
        .section { font-size: 9pt; font-weight: bold; margin: 16px 0 6px; }
        .lines th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5px; color: #657180; background: #f5f7f9; padding: 5px; border-bottom: 1px solid #e1e6eb; }
        .lines td { padding: 5px; border-bottom: 1px solid #e1e6eb; font-size: 7.5pt; }
        .num { text-align: right; white-space: nowrap; }
        .notes { margin-top: 14px; padding: 8px 10px; background: #f5f7f9; border: 1px dashed #c9d1d9; color: #657180; font-size: 7.5pt; }
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
                <div class="brand-name">{{ $facility?->name }}</div>
                @if ($facility?->address)
                    <div class="muted">{{ $facility->address }}</div>
                @endif
                <div class="muted">Billing counter</div>
            </td>
            <td class="doc-title">
                <div class="name">{{ $closed ? 'CASH SHIFT REPORT (Z)' : 'CASH SHIFT READING (X)' }}</div>
                <div class="muted">Shift No. {{ $report['session_number'] }}</div>
                <div class="muted">Printed: {{ now()->timezone($tz)->format('j M Y, g:i A') }}</div>
                <div style="margin-top: 4px;">
                    <span class="pill pill-{{ $closed ? 'closed' : 'open' }}">{{ $closed ? 'CLOSED' : 'OPEN — NOT YET COUNTED' }}</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="boxes">
        <tr>
            <td class="box">
                <h3>Shift</h3>
                <table class="kv">
                    <tr><td class="k">Cashier</td><td class="v">{{ $report['cashier']['name'] ?? '—' }}</td></tr>
                    @if ($report['counter_label'])
                        <tr><td class="k">Counter</td><td class="v">{{ $report['counter_label'] }}</td></tr>
                    @endif
                    <tr><td class="k">Opened</td><td class="v">{{ $when($report['opened_at']) }}</td></tr>
                    <tr><td class="k">Closed</td><td class="v">{{ $when($report['closed_at']) }}</td></tr>
                    @if ($closed && $report['closed_by'])
                        <tr><td class="k">Closed by</td><td class="v">{{ $report['closed_by'] }}</td></tr>
                    @endif
                    <tr><td class="k">Payments taken</td><td class="v">{{ $report['counts']['payments'] }}</td></tr>
                    <tr><td class="k">Payments voided</td><td class="v">{{ $report['counts']['voids'] }}</td></tr>
                </table>
            </td>
            <td class="gap"></td>
            <td class="box">
                <h3>Cash drawer</h3>
                <table class="kv">
                    <tr><td class="k">Opening float</td><td class="v">{{ $peso($figures['opening_float']) }}</td></tr>
                    <tr><td class="k">Cash taken</td><td class="v">{{ $peso($figures['cash_collected']) }}</td></tr>
                    <tr><td class="k">Cash voided</td><td class="v">{{ $peso(-(float) $figures['cash_voided']) }}</td></tr>
                    <tr class="total"><td class="k">Expected in drawer</td><td class="v">{{ $peso($figures['expected_cash']) }}</td></tr>
                    @if ($closed)
                        <tr><td class="k">Counted</td><td class="v">{{ $peso($figures['counted_cash']) }}</td></tr>
                        <tr class="{{ $variance < 0 ? 'short' : ($variance > 0 ? 'over' : 'even') }}">
                            <td class="k">{{ $variance < 0 ? 'Short' : ($variance > 0 ? 'Over' : 'Difference') }}</td>
                            <td class="v">{{ $peso($variance) }}</td>
                        </tr>
                    @endif
                </table>
                <h3 style="margin-top: 8px;">Not in the drawer</h3>
                <table class="kv">
                    <tr><td class="k">GCash recorded at the counter</td><td class="v">{{ $peso($figures['gcash_counter']) }}</td></tr>
                    <tr><td class="k">GCash checkouts</td><td class="v">{{ $peso($figures['gcash_checkout']) }}</td></tr>
                    <tr class="total"><td class="k">Total collected this shift</td><td class="v">{{ $peso($figures['total_collected']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($closed && ! empty($report['count_breakdown']))
        <div class="section">Drawer count</div>
        <table class="lines">
            <thead>
                <tr><th>Note or coin</th><th class="num">Count</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($report['count_breakdown'] as $denomination => $count)
                    @if ((int) $count > 0)
                        <tr>
                            <td>{{ $peso($denomination) }}</td>
                            <td class="num">{{ $count }}</td>
                            <td class="num">{{ $peso((float) $denomination * (int) $count) }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section">Transactions in this shift</div>
    <table class="lines">
        <thead>
            <tr>
                <th>Transaction</th>
                <th>Time</th>
                <th>Request</th>
                <th>Type</th>
                <th>Method</th>
                <th>Receipt</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['transactions'] as $row)
                <tr>
                    <td>{{ $row['transaction_number'] }}</td>
                    <td>{{ $row['occurred_at'] ? \Illuminate\Support\Carbon::parse($row['occurred_at'])->timezone($tz)->format('g:i A') : '' }}</td>
                    <td>{{ $row['request']['reference_number'] ?? '—' }}</td>
                    <td>{{ $row['type_label'] }}</td>
                    <td>{{ $row['payment_method_label'] ?? '—' }}</td>
                    <td>{{ $row['receipt']['receipt_number'] ?? '—' }}</td>
                    {{-- Money received reads as positive here: the journal signs it against the bill. --}}
                    <td class="num">{{ $peso(-(float) $row['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Nothing was taken in this shift.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="notes">
        <strong>Notes</strong>
        @if ($closed && $report['closing_note'])
            <p>{{ $report['closing_note'] }}</p>
        @endif
        <p>
            {{ $closed
                ? 'The expected cash was worked out from this shift\'s transactions when it was closed, and is frozen beside the count.'
                : 'This is a reading of a shift still open. Its figures change until the drawer is counted and the shift closed.' }}
        </p>
        <table class="sign">
            <tr>
                <td>Cashier: ______________________</td>
                <td>Verified by: ______________________</td>
            </tr>
        </table>
    </div>

    <table class="footer">
        <tr>
            <td>{{ $facility?->name }} &middot; Cash Shift Report</td>
            <td style="text-align: center;">Generated by RedAgos</td>
            <td style="text-align: right;">{{ $report['session_number'] }}</td>
        </tr>
    </table>
</body>
</html>
