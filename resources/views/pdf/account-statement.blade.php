{{--
    Statement of Account: one hospital's weekly invoices, its settlements and
    its balance over a period (project owner, 2026-10-25).

    Built on demand from the billing journal and never stored, so it carries
    the period rather than a number. Tables only and absolute sizes, because
    dompdf supports neither flex nor grid. Headed by the issuing centre, its
    logo or its initials, in the same letterhead as the invoice and receipt.
--}}
@php
    $peso = fn (string|int|float|null $amount): string => '₱'.number_format((float) ($amount ?? 0), 2);
    $timezone = config('blood_center.timezone');
    $day = fn (?string $value): string => $value ? \Carbon\CarbonImmutable::parse($value)->timezone($timezone)->format('j M Y') : '—';
    $period = \Carbon\CarbonImmutable::parse($statement['period']['from'])->format('j M Y').' – '.\Carbon\CarbonImmutable::parse($statement['period']['to'])->format('j M Y');
    $contact = implode('  ·  ', array_filter([
        $facility?->doh_license_number ? 'DOH licence '.$facility->doh_license_number : null,
        $facility?->phone,
        $facility?->email,
    ]));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account · {{ $statement['hospital']['name'] }} · {{ $period }}</title>
    <style>
        @page { size: A4; margin: 0 0 14mm; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.4; color: #17212b; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }
        .page { padding: 0 13mm; }
        .mono { font-family: "DejaVu Sans Mono", monospace; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #657180; }

        .edge { height: 4px; background: #c62828; }
        .edge-blue { height: 2px; background: #1565c0; margin-bottom: 9mm; }

        .brand-mark { vertical-align: middle; }
        .brand-mark-logo { width: 134px; }
        .brand-mark-initials { width: 62px; }
        .issuer-logo { max-width: 120px; max-height: 54px; }
        .monogram { width: 50px; padding: 14px 0; background: #1565c0; color: #fff; border-radius: 12px; text-align: center; font-size: 15pt; font-weight: bold; line-height: 1; }
        .brand-name { font-size: 14pt; font-weight: bold; line-height: 1.2; }
        .brand-sub { margin-top: 2px; font-size: 7.5pt; color: #657180; }

        .doc-title { text-align: right; }
        .doc-kicker { font-size: 7pt; font-weight: bold; letter-spacing: 1.2px; color: #1565c0; text-transform: uppercase; }
        .doc-period { margin-top: 3px; font-size: 11pt; font-weight: bold; }
        .doc-date { margin-top: 1px; font-size: 7.5pt; color: #657180; }

        .hero { margin-top: 7mm; background: #f2f6fc; border: 1px solid #dbe5f3; border-radius: 10px; }
        .hero td.hero-main { padding: 14px 16px; width: 48%; border-right: 1px solid #dbe5f3; }
        .hero td.hero-side { padding: 14px 16px; vertical-align: middle; }
        .hero-label { font-size: 7pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #657180; }
        .hero-amount { margin-top: 3px; font-size: 22pt; font-weight: bold; color: #0d3c7a; line-height: 1.15; }
        .hero-party { font-size: 12pt; font-weight: bold; margin-top: 2px; }
        .hero-side p { margin: 2px 0 0; font-size: 7.8pt; color: #657180; }

        .figures { margin-top: 6mm; border: 1px solid #e1e6eb; border-radius: 8px; }
        .figures td { width: 25%; padding: 9px 12px; border-left: 1px solid #e1e6eb; }
        .figures td:first-child { border-left: none; }
        .cap { font-size: 6.8pt; font-weight: bold; letter-spacing: 0.9px; text-transform: uppercase; color: #657180; margin-bottom: 3px; }
        .figure { font-size: 10.5pt; font-weight: bold; }

        .section { margin: 7mm 0 2mm; font-size: 9pt; font-weight: bold; }
        .lines th { text-align: left; font-size: 6.8pt; text-transform: uppercase; letter-spacing: 0.7px; color: #657180; padding: 6px 8px; border-bottom: 1.5px solid #17212b; }
        .lines th.num { text-align: right; }
        .lines td { padding: 6px 8px; border-bottom: 1px solid #e6eaef; }
        .lines tr.carried td { color: #657180; font-style: italic; }
        .lines tr.closing td { font-weight: bold; border-bottom: none; background: #e8f0fb; color: #0d3c7a; }
        .empty { padding: 10px 8px; color: #657180; }

        .notes { margin-top: 8mm; font-size: 7.5pt; color: #657180; }
        .notes p { margin: 2px 0 0; }

        .footer { position: fixed; bottom: -9mm; left: 13mm; right: 13mm; border-top: 1px solid #e1e6eb; padding-top: 5px; font-size: 6.8pt; color: #8a94a1; }
    </style>
</head>
<body>
    <div class="edge"></div>
    <div class="edge-blue"></div>

    <div class="footer">
        <table>
            <tr>
                <td>{{ $facility?->name }} &middot; Statement of Account</td>
                <td style="text-align: center;">Keep this document for reference &middot; Generated by RedAgos</td>
                <td style="text-align: right;">{{ $period }}</td>
            </tr>
        </table>
    </div>

    <div class="page">
        <table>
            <tr>
                <td class="brand-mark {{ $logo ? 'brand-mark-logo' : 'brand-mark-initials' }}">
                    @if ($logo)
                        <img class="issuer-logo" src="{{ $logo }}" alt="">
                    @else
                        <div class="monogram">{{ $monogram }}</div>
                    @endif
                </td>
                <td style="vertical-align: middle;">
                    <div class="brand-name">{{ $facility?->name }}</div>
                    @if ($facility?->address)
                        <div class="brand-sub">{{ $facility->address }}</div>
                    @endif
                    @if ($contact !== '')
                        <div class="brand-sub">{{ $contact }}</div>
                    @endif
                </td>
                <td class="doc-title" style="width: 38%; vertical-align: middle;">
                    <div class="doc-kicker">Statement of Account</div>
                    <div class="doc-period">{{ $period }}</div>
                    <div class="doc-date">Generated {{ \Carbon\CarbonImmutable::parse($statement['generated_at'])->timezone($timezone)->format('j M Y, g:i A') }}</div>
                </td>
            </tr>
        </table>

        <table class="hero">
            <tr>
                <td class="hero-main">
                    <div class="hero-label">Remaining balance</div>
                    <div class="hero-amount">{{ $peso($statement['closing_balance']) }}</div>
                </td>
                <td class="hero-side">
                    <div class="hero-label">Bill to</div>
                    <div class="hero-party">{{ $statement['hospital']['name'] }}</div>
                    @if ($statement['hospital']['address'])
                        <p>{{ $statement['hospital']['address'] }}</p>
                    @endif
                </td>
            </tr>
        </table>

        <table class="figures">
            <tr>
                <td><div class="cap">Opening balance</div><div class="figure">{{ $peso($statement['opening_balance']) }}</div></td>
                <td><div class="cap">Invoiced</div><div class="figure">{{ $peso($statement['invoiced']) }}</div></td>
                <td><div class="cap">Payments</div><div class="figure">{{ $peso($statement['payments']) }}</div></td>
                <td><div class="cap">Closing balance</div><div class="figure">{{ $peso($statement['closing_balance']) }}</div></td>
            </tr>
        </table>

        <div class="section">Invoices</div>
        <table class="lines">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Request</th>
                    <th>Billed on</th>
                    <th class="num">Amount</th>
                    <th class="num">Settled</th>
                    <th class="num">Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($statement['invoices'] as $invoice)
                    <tr>
                        <td class="mono">{{ $invoice['invoice_number'] ?? 'Not issued' }}</td>
                        <td class="mono">{{ $invoice['request_reference'] }}</td>
                        <td>{{ $day($invoice['billed_on']) }}</td>
                        <td class="num">{{ $peso($invoice['amount']) }}</td>
                        <td class="num">{{ $peso($invoice['settled']) }}</td>
                        <td class="num">{{ $peso($invoice['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No invoices in this period.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="section">Activity</div>
        <table class="lines">
            <thead>
                <tr>
                    <th style="width: 15%;">Date</th>
                    <th>Description</th>
                    <th class="num" style="width: 15%;">Charges</th>
                    <th class="num" style="width: 15%;">Payments</th>
                    <th class="num" style="width: 15%;">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="carried">
                    <td>{{ $day($statement['period']['from']) }}</td>
                    <td>Opening balance</td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num">{{ $peso($statement['opening_balance']) }}</td>
                </tr>
                @foreach ($statement['activity'] as $row)
                    <tr>
                        <td>{{ $day($row['occurred_at']) }}</td>
                        <td>
                            {{ $row['type_label'] }}
                            &middot; <span class="mono">{{ $row['invoice_number'] ?? $row['request_reference'] }}</span>
                            @if ($row['reference'])
                                &middot; ref <span class="mono">{{ $row['reference'] }}</span>
                            @endif
                        </td>
                        <td class="num">{{ $row['charge'] ? $peso($row['charge']) : '' }}</td>
                        <td class="num">{{ $row['payment'] ? $peso($row['payment']) : '' }}</td>
                        <td class="num">{{ $peso($row['balance']) }}</td>
                    </tr>
                @endforeach
                <tr class="closing">
                    <td>{{ $day($statement['period']['to']) }}</td>
                    <td>Closing balance</td>
                    <td class="num">{{ $peso($statement['invoiced']) }}</td>
                    <td class="num">{{ $peso($statement['payments']) }}</td>
                    <td class="num">{{ $peso($statement['closing_balance']) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="notes">
            <p>
                Covers the weekly replenishment invoices billed to {{ $statement['hospital']['name'] }}. Patient
                transfusion invoices are paid by the patient and do not appear here. This statement is not a BIR document.
            </p>
        </div>
    </div>
</body>
</html>
