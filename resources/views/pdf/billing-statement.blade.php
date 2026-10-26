{{--
    Invoice: one frozen revision of a request's bill.

    Rendered only from the revision's own rows, never the live statement, so
    the same revision always prints the same document. Tables only and
    absolute sizes, because dompdf supports neither flex nor grid. The logo
    arrives as a data URI (remote fetching is off) and may be absent.

    Redesigned 2026-10-14 to match the receipt: what is owed stated once,
    large, beside who owes it; the request, the lines, the totals with what the
    status means beside them, the notes and signatures. Blue carries the
    document, with red only as the thin brand edge at the top. Titled an
    Invoice since 2026-10-25 (project owner); it is not a BIR document and says so.

    Headed by the issuing centre — its own logo, the one frozen on the
    revision, or its initials when it has none — never by a shared mark.
    RedAgos is named only in the footer, as the system that produced it.
--}}
@php
    use App\Enums\BillingStatus;

    $peso = fn (string|int|float|null $amount): string => '₱'.number_format((float) ($amount ?? 0), 2);
    $status = $revision->billing_status;
    $facility = $revision->issuingFacility;
    $subsidised = $status === BillingStatus::Subsidised;
    $void = $status === BillingStatus::Void;
    // A subsidy waives the whole charge; anything collected before it stays
    // recorded and is refunded, if at all, outside RedAgos.
    $subsidy = $subsidised ? (float) $revision->total_amount : 0.0;
    $payable = (float) $revision->total_amount - $subsidy;
    $collected = (float) $revision->collected_at_issue;
    $showsPaid = $collected > 0 && ! $subsidised;
    $issuedBy = $revision->creator ? trim($revision->creator->first_name.' '.$revision->creator->last_name) : null;
    $tone = match ($status) {
        BillingStatus::Paid, BillingStatus::SettledOutside => 'paid',
        BillingStatus::Subsidised, BillingStatus::StatementOnly => 'info',
        BillingStatus::Void => 'void',
        BillingStatus::Partial => 'part',
        default => 'due',
    };
    // The headline figure: what is still owed once something was paid,
    // otherwise what the statement charges. The totals show how it adds up.
    if ($revision->statement_only) {
        $hero = ['label' => 'Amount billed', 'amount' => $payable];
    } elseif ($showsPaid) {
        $hero = ['label' => 'Outstanding balance', 'amount' => (float) $revision->amount_due];
    } else {
        $hero = ['label' => 'Amount payable', 'amount' => $payable];
    }
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
    <title>Invoice {{ $revision->document_number }}</title>
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
        .monogram { width: 50px; padding: 14px 0; background: #1565c0; color: #fff; border-radius: 12px; text-align: center; font-size: 15pt; font-weight: bold; line-height: 1; }
        .brand-name { font-size: 14pt; font-weight: bold; line-height: 1.2; }
        .brand-sub { margin-top: 2px; font-size: 7.5pt; color: #657180; }

        .doc-title { text-align: right; }
        .doc-kicker { font-size: 7pt; font-weight: bold; letter-spacing: 1.2px; color: #1565c0; text-transform: uppercase; }
        .doc-no { margin-top: 3px; font-size: 11pt; font-weight: bold; }
        .doc-date { margin-top: 1px; font-size: 7.5pt; color: #657180; }

        .void-banner { margin-top: 7mm; padding: 7px 10px; border: 1px solid #f1c2c2; background: #fdf1f1; color: #a42020; font-size: 8pt; border-radius: 6px; }

        /* The one figure: what is owed, beside who owes it. */
        .hero { margin-top: 7mm; background: #f2f6fc; border: 1px solid #dbe5f3; border-radius: 10px; }
        .hero td.hero-main { padding: 14px 16px; width: 48%; border-right: 1px solid #dbe5f3; }
        .hero td.hero-side { padding: 14px 16px; vertical-align: middle; }
        .hero-label { font-size: 7pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #657180; }
        .hero-amount { margin-top: 3px; font-size: 22pt; font-weight: bold; color: #0d3c7a; line-height: 1.15; }
        .hero-amount.void { color: #8a94a1; text-decoration: line-through; }
        .hero-party { font-size: 12pt; font-weight: bold; margin-top: 2px; }
        .hero-side p { margin: 2px 0 0; font-size: 7.8pt; color: #657180; }
        .pill { display: inline-block; margin-top: 7px; padding: 2px 8px; border-radius: 9px; font-size: 7pt; font-weight: bold; letter-spacing: 0.4px; }
        .pill-due { background: #fde8e8; color: #a42020; }
        .pill-paid { background: #dff4e9; color: #126a49; }
        .pill-part { background: #fdf0d9; color: #8a5600; }
        .pill-info { background: #e3ecfa; color: #1553a0; }
        .pill-void { background: #e9ecef; color: #4b5563; }

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

        .summary { margin-top: 6mm; }
        .summary td.left { width: 50%; padding-right: 18px; }
        .summary td.right { width: 50%; }
        .status-box { border: 1px solid #e1e6eb; border-radius: 8px; padding: 8px 10px; font-size: 7.8pt; }
        .totals td { padding: 5px 8px; border-bottom: 1px solid #e6eaef; font-size: 8.2pt; }
        .totals tr.less td { color: #126a49; }
        .totals tr.this td { font-weight: bold; color: #0d3c7a; }
        .totals tr.end td { font-weight: bold; border-bottom: none; }
        .totals tr.end.due td { background: #fdf0d9; color: #8a5600; }
        .totals tr.end.settled td { background: #dff4e9; color: #126a49; }
        .totals tr.end.total td { background: #e8f0fb; color: #0d3c7a; font-size: 9pt; }

        .notes { margin-top: 8mm; font-size: 7.5pt; color: #657180; }
        .notes p { margin: 2px 0 0; }
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
                <td>{{ $facility?->name }} &middot; Invoice</td>
                <td style="text-align: center;">Keep this document for reference &middot; Generated by RedAgos</td>
                <td class="mono" style="text-align: right;">{{ $revision->document_number }}</td>
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
                    <div class="doc-kicker">Invoice</div>
                    <div class="doc-no mono">{{ $revision->document_number }}</div>
                    <div class="doc-date">Revision {{ $revision->revision_number }} · Issued {{ $revision->created_at?->timezone(config('blood_center.timezone'))->format('j M Y, g:i A') }}</div>
                </td>
            </tr>
        </table>

        @if ($void)
            <div class="void-banner"><strong>This invoice has been voided.</strong></div>
        @endif

        {{-- What is owed, once, beside who owes it --}}
        <table class="hero">
            <tr>
                <td class="hero-main">
                    <div class="hero-label">{{ $hero['label'] }}</div>
                    <div class="hero-amount {{ $void ? 'void' : '' }}">{{ $peso($hero['amount']) }}</div>
                    <span class="pill pill-{{ $tone }}">{{ mb_strtoupper($status->label()) }}</span>
                </td>
                <td class="hero-side">
                    @if ($revision->statement_only)
                        <div class="cap">Bill to</div>
                        <div class="hero-party">{{ $revision->payerFacility?->name }}</div>
                        <p>Requesting hospital</p>
                    @else
                        <div class="cap">Bill to / Patient</div>
                        <div class="hero-party">{{ $patient ?? 'Patient' }}</div>
                        <p>c/o {{ $revision->payerFacility?->name }}</p>
                        <p>Payable by the patient or their watcher</p>
                    @endif
                </td>
            </tr>
        </table>

        {{-- The request and its terms --}}
        <table class="parties">
            <tr>
                <td class="cell">
                    <div class="cap">Blood request</div>
                    <strong class="mono">{{ $request?->reference_number }}</strong>
                    <p>{{ $revision->statement_only ? 'Weekly replenishment' : 'Patient transfusion' }}</p>
                </td>
                <td class="cell">
                    <div class="cap">Payable</div>
                    <strong>{{ $revision->statement_only ? 'Settled outside RedAgos' : 'Before the units are released' }}</strong>
                </td>
                <td class="cell">
                    <div class="cap">Currency</div>
                    <strong>PHP (₱)</strong>
                </td>
            </tr>
        </table>

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
                @forelse ($revision->items as $item)
                    <tr>
                        <td class="idx">{{ $loop->iteration }}</td>
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

        {{-- What the status means, beside the totals --}}
        <table class="summary">
            <tr>
                <td class="left">
                    @if ($status === BillingStatus::StatementOnly)
                        <div class="cap">Note</div>
                        <div class="status-box">
                            Weekly replenishment order. This invoice goes to the requesting hospital for settlement
                            outside RedAgos; no payment is collected against it in this system.
                        </div>
                    @elseif ($status === BillingStatus::SettledOutside)
                        <div class="cap">Note</div>
                        <div class="status-box">
                            Weekly replenishment order, settled by the requesting hospital outside RedAgos{{
                                $revision->billing?->settled_at ? ' on '.$revision->billing->settled_at->format('j M Y') : ''
                            }}{{
                                $revision->billing?->settlement_reference ? ' (reference '.$revision->billing->settlement_reference.')' : ''
                            }}.
                        </div>
                    @elseif ($subsidised)
                        <div class="cap">Note</div>
                        <div class="status-box">
                            Covered by the government subsidy. Nothing is payable on this request.
                            @if ($collected > 0)
                                {{ $peso($collected) }} was received before the subsidy; any refund is settled outside RedAgos.
                            @endif
                        </div>
                    @elseif ($void)
                        {{-- The banner at the top says it. --}}
                    @elseif ($collected > 0)
                        <div class="cap">Note</div>
                        <div class="status-box">Payments received before this invoice are deducted above.</div>
                    @endif
                </td>
                <td class="right">
                    <div class="cap">Totals</div>
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
                        @if ($showsPaid)
                            <tr class="this">
                                <td>{{ $revision->statement_only ? 'Amount billed' : 'Amount payable' }}</td>
                                <td class="num">{{ $peso($payable) }}</td>
                            </tr>
                            <tr>
                                <td>Amount already paid</td>
                                <td class="num">− {{ $peso($collected) }}</td>
                            </tr>
                            <tr class="end {{ (float) $revision->amount_due > 0 ? 'due' : 'settled' }}">
                                <td>Outstanding balance</td>
                                <td class="num">{{ $peso($revision->amount_due) }}</td>
                            </tr>
                        @else
                            <tr class="end total">
                                <td>{{ $revision->statement_only ? 'Amount billed' : 'Amount payable' }}</td>
                                <td class="num">{{ $peso($payable) }}</td>
                            </tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        <div class="notes">
            <p>
                This invoice is not a BIR-registered invoice and not an official receipt. Payment is acknowledged
                by a separate Payment Acknowledgement Receipt. The figures are those frozen when this revision
                was issued.
            </p>
        </div>

        <table class="sign">
            <tr>
                <td>
                    <div class="line"><span class="who">{{ $issuedBy ?? ' ' }}</span><br>Prepared by</div>
                </td>
                <td>
                    <div class="line"><span class="who">&nbsp;</span><br>Authorized by</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
