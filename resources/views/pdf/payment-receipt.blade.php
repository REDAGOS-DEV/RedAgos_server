{{--
    Payment Acknowledgement Receipt, rendered only from the receipt's snapshot.

    The snapshot was frozen when the payment was recorded, so a later top-up,
    subsidy or correction never changes a receipt already handed over. A
    partial payment shows the balance before and after it, so it can never
    read as settling the whole statement.

    Not a BIR official receipt: the receipt says so.
--}}
@php
    $peso = fn (?string $amount): string => '₱'.number_format((float) ($amount ?? 0), 2);
    $after = (float) ($snapshot['balance_after'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Acknowledgement Receipt {{ $snapshot['receipt_number'] ?? '' }}</title>
    <style>
        @page { size: A5; margin: 10mm 11mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.35; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        .head td { vertical-align: middle; }
        .head .logo { width: 56px; }
        .head img { max-width: 50px; max-height: 50px; }
        .facility { font-size: 10pt; font-weight: bold; text-transform: uppercase; }
        .muted { color: #555; }
        .title { font-size: 12pt; font-weight: bold; margin: 10px 0 0; }
        .stamp { display: inline-block; padding: 2px 6px; border: 1px solid #333; font-size: 7.5pt; font-weight: bold; margin-top: 4px; }
        .void { color: #a40000; border-color: #a40000; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .meta .label { width: 40%; color: #555; }
        .money { margin-top: 10px; }
        .money td { padding: 3px 0; }
        .money .paid td { font-weight: bold; font-size: 10.5pt; border-top: 1px solid #333; border-bottom: 1px solid #333; }
        .num { text-align: right; white-space: nowrap; }
        .footer { margin-top: 16px; font-size: 7pt; color: #555; border-top: 1px solid #ccc; padding-top: 5px; }
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
                <div class="facility">{{ $snapshot['issuing_facility']['name'] ?? '' }}</div>
                @if (! empty($snapshot['issuing_facility']['address']))
                    <div class="muted">{{ $snapshot['issuing_facility']['address'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="title">PAYMENT ACKNOWLEDGEMENT RECEIPT</div>
    <div class="muted">{{ $snapshot['receipt_number'] ?? '' }}</div>
    @if (! empty($snapshot['is_partial']))
        <span class="stamp">PARTIAL PAYMENT</span>
    @endif
    @if ($receipt->isVoided())
        <span class="stamp void">VOID — {{ $receipt->void_reason }}</span>
    @endif

    <table class="meta" style="margin-top: 8px;">
        <tr>
            <td class="label">Issued</td>
            <td>{{ \Illuminate\Support\Carbon::parse($snapshot['issued_at'])->timezone(config('blood_center.timezone'))->format('j F Y, g:i A') }}</td>
        </tr>
        @if (! empty($snapshot['payer_name']))
            <tr>
                <td class="label">Received from</td>
                <td>{{ $snapshot['payer_name'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Blood request</td>
            <td>{{ $snapshot['request']['reference_number'] ?? '' }}</td>
        </tr>
        @if (! empty($snapshot['request']['patient_name']))
            <tr>
                <td class="label">Patient</td>
                <td>{{ $snapshot['request']['patient_name'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Requesting facility</td>
            <td>{{ $snapshot['request']['requesting_facility'] ?? '' }}</td>
        </tr>
        @if (! empty($snapshot['statement']))
            <tr>
                <td class="label">Statement</td>
                <td>{{ $snapshot['statement']['document_number'] }} (revision {{ $snapshot['statement']['revision_number'] }})</td>
            </tr>
        @endif
        <tr>
            <td class="label">Method</td>
            <td>
                {{ $snapshot['payment']['method_label'] ?? '' }}
                @if (! empty($snapshot['payment']['reference_number']))
                    &middot; Ref {{ $snapshot['payment']['reference_number'] }}
                @endif
            </td>
        </tr>
        @if (! empty($snapshot['replaces_receipt_number']))
            <tr>
                <td class="label">Replaces receipt</td>
                <td>{{ $snapshot['replaces_receipt_number'] }}</td>
            </tr>
        @endif
    </table>

    <table class="money">
        <tr>
            <td>Balance before this payment</td>
            <td class="num">{{ $peso($snapshot['balance_before'] ?? null) }}</td>
        </tr>
        <tr class="paid">
            <td>Amount received</td>
            <td class="num">{{ $peso($snapshot['amount_paid'] ?? null) }}</td>
        </tr>
        <tr>
            @if ($after < 0)
                <td>Received in excess of the balance</td>
                <td class="num">{{ $peso((string) abs($after)) }}</td>
            @else
                <td>Balance remaining</td>
                <td class="num">{{ $peso($snapshot['balance_after'] ?? null) }}</td>
            @endif
        </tr>
    </table>

    <table class="meta" style="margin-top: 10px;">
        <tr>
            <td class="label">Received by</td>
            <td>{{ $snapshot['received_by'] ?? 'Confirmed by the payment provider' }}</td>
        </tr>
    </table>

    <div class="footer">
        This acknowledges payment received. It is not a BIR official receipt.
    </div>
</body>
</html>
