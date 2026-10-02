{{--
    The Daily Blood Stock Inventory, as SNBC-Mindanao prints it.

    Tables only, with absolute font sizes: dompdf supports neither flex nor
    grid. Each blood type is one band in its ABO colour, as on the sheet. A
    component broken down by expiry date takes two lines per blood type —
    the dates, then the counts under them — and a total; any other component
    takes one merged cell.

    Images arrive as data URIs, because the PDF is rendered with remote
    fetching off: the DOH seal from the application, the logo from the
    facility's own upload. Either may be absent, and the header still reads.
--}}
@php
    use Illuminate\Support\Carbon;

    $band = fn (?string $abo): string => match ($abo) {
        'A' => 'band-a',
        'B' => 'band-b',
        'O' => 'band-o',
        'AB' => 'band-ab',
        default => '',
    };

    $day = fn (string $date): string => Carbon::parse($date)->format('j-M');

    $width = fn (array $column): int => $column['dated'] ? $column['date_slots'] + 1 : 1;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Daily Blood Stock Inventory — {{ $report['facility']['name'] }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm 9mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 7pt;
            line-height: 1.2;
            color: #000;
        }

        table { border-collapse: collapse; }

        .head { width: 100%; }
        .head td { vertical-align: middle; }
        .head .seal, .head .logo { width: 70px; text-align: center; }
        .head img { max-width: 62px; max-height: 62px; }
        .head .lines { text-align: center; font-size: 8pt; line-height: 1.35; }
        .head .facility { font-weight: bold; font-size: 8.6pt; text-transform: uppercase; }

        .title {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            margin: 6px 0 2px;
        }

        .legend { text-align: center; font-size: 6.4pt; color: #333; margin-bottom: 6px; }

        .grid { width: 100%; margin-top: 7px; }
        .grid th, .grid td {
            border: .6px solid #555;
            padding: 1.5px 2.5px;
            text-align: center;
            vertical-align: middle;
        }

        .grid .table-title { font-size: 8pt; font-weight: bold; letter-spacing: .4px; }
        .grid .rh-positive { background: #fff2b3; }
        .grid .rh-negative { background: #f9c9d2; }
        .grid .rh-all { background: #e4e4e4; }

        .grid .col-head { font-weight: bold; font-size: 7pt; background: #f2f2f2; }
        .grid .sub { font-weight: normal; font-size: 5.8pt; color: #333; background: #f2f2f2; }
        .grid .flag { display: block; font-weight: normal; font-size: 5.4pt; color: #a00; }

        .grid .type { font-weight: bold; font-size: 7.6pt; width: 34px; }
        .grid .date { font-size: 5.8pt; color: #222; }
        .grid .num { font-size: 7pt; }
        .grid .total { font-weight: bold; }
        .grid .zero { color: #666; }

        /* Expiring today or tomorrow: the line a medical technologist acts on first. */
        .grid .soon { font-weight: bold; color: #b00000; border: 1.4px solid #b00000; }

        .grid .foot td { font-weight: bold; background: #f2f2f2; }

        .band-a { background: #bfe6f7; }
        .band-b { background: #fff3a0; }
        .band-o { background: #dcdcdc; }
        .band-ab { background: #f7b9c4; }

        .by { margin-top: 12px; font-size: 7.6pt; }
        .by strong { text-decoration: underline; }
        .note { margin-top: 3px; font-size: 6pt; color: #333; }
    </style>
</head>
<body>

<table class="head">
    <tr>
        <td class="seal">
            @if ($seal)
                <img src="{{ $seal }}" alt="Department of Health">
            @endif
        </td>
        <td class="lines">
            @foreach ($report['header'] as $line)
                <div>{{ $line }}</div>
            @endforeach
            <div class="facility">{{ $report['facility']['name'] }}</div>
        </td>
        <td class="logo">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $report['facility']['name'] }}">
            @endif
        </td>
    </tr>
</table>

<div class="title">
    DAILY BLOOD STOCK INVENTORY AS OF {{ $report['as_of_date'] }} at {{ $report['as_of_time'] }}
</div>
<div class="legend">
    Issuable units only (available, not past expiry).
    Boxed red counts expire today or tomorrow.
    Components with a shelf life of {{ $report['reference']['shelf_life_days'] }} days or less are listed by expiry date.
</div>

@foreach ($report['tables'] as $table)
    @php
        $hasDated = collect($table['columns'])->contains(fn ($c) => $c['dated']);
        $span = 1 + array_sum(array_map($width, $table['columns']));
    @endphp

    <table class="grid">
        <tr>
            <th class="table-title rh-{{ $table['rh'] }}" colspan="{{ $span }}">{{ $table['title'] }}</th>
        </tr>

        <tr>
            <th class="col-head" rowspan="{{ $hasDated ? 2 : 1 }}"></th>
            @foreach ($table['columns'] as $column)
                @if ($column['dated'])
                    <th class="col-head" colspan="{{ $width($column) }}">{{ strtoupper($column['name']) }}</th>
                @else
                    <th class="col-head" rowspan="{{ $hasDated ? 2 : 1 }}">
                        {{ strtoupper($column['name']) }}
                        @unless ($column['shelf_life_configured'])
                            <span class="flag">Shelf life not configured</span>
                        @endunless
                    </th>
                @endif
            @endforeach
        </tr>

        @if ($hasDated)
            <tr>
                @foreach ($table['columns'] as $column)
                    @if ($column['dated'])
                        <th class="sub" colspan="{{ $column['date_slots'] }}">by expiry date</th>
                        <th class="sub">TOTAL</th>
                    @endif
                @endforeach
            </tr>
        @endif

        @foreach ($table['rows'] as $row)
            @php $bandClass = $band($row['abo']); @endphp

            @if ($hasDated)
                {{-- The dates line --}}
                <tr>
                    <td class="type {{ $bandClass }}" rowspan="2">{{ $row['blood_type'] }}</td>
                    @foreach ($table['columns'] as $column)
                        @php $cell = $row['cells'][$column['id']]; @endphp
                        @if ($column['dated'])
                            @for ($i = 0; $i < $column['date_slots']; $i++)
                                <td class="date {{ $bandClass }}">
                                    {{ isset($cell['by_expiry'][$i]) ? $day($cell['by_expiry'][$i]['date']) : '' }}
                                </td>
                            @endfor
                            <td class="num total {{ $bandClass }} {{ $cell['total'] === 0 ? 'zero' : '' }}" rowspan="2">{{ $cell['total'] }}</td>
                        @else
                            <td class="num {{ $bandClass }} {{ $cell['total'] === 0 ? 'zero' : '' }} {{ $cell['expiring_soon'] > 0 ? 'soon' : '' }}" rowspan="2">{{ $cell['total'] }}</td>
                        @endif
                    @endforeach
                </tr>
                {{-- The counts line --}}
                <tr>
                    @foreach ($table['columns'] as $column)
                        @if ($column['dated'])
                            @php $cell = $row['cells'][$column['id']]; @endphp
                            @for ($i = 0; $i < $column['date_slots']; $i++)
                                @php $entry = $cell['by_expiry'][$i] ?? null; @endphp
                                <td class="num {{ $bandClass }} {{ $entry && ($entry['expires_today'] || $entry['expires_tomorrow']) ? 'soon' : '' }}">
                                    {{ $entry['units'] ?? '' }}
                                </td>
                            @endfor
                        @endif
                    @endforeach
                </tr>
            @else
                <tr>
                    <td class="type {{ $bandClass }}">{{ $row['blood_type'] }}</td>
                    @foreach ($table['columns'] as $column)
                        @php $cell = $row['cells'][$column['id']]; @endphp
                        <td class="num {{ $bandClass }} {{ $cell['total'] === 0 ? 'zero' : '' }} {{ $cell['expiring_soon'] > 0 ? 'soon' : '' }}">{{ $cell['total'] }}</td>
                    @endforeach
                </tr>
            @endif
        @endforeach

        <tr class="foot">
            <td>TOTAL</td>
            @foreach ($table['columns'] as $column)
                @if ($column['dated'])
                    <td colspan="{{ $column['date_slots'] }}"></td>
                @endif
                <td>{{ $column['total'] }}</td>
            @endforeach
        </tr>
    </table>
@endforeach

<div class="by">BY: <strong>{{ $report['prepared_by'] }}</strong></div>

@if ($report['unconfigured'] !== [])
    <div class="note">
        Shelf life not configured for: {{ implode(', ', $report['unconfigured']) }}.
        Their stock is shown as totals only.
    </div>
@endif

@if ($report['reference']['is_fallback'])
    <div class="note">
        {{ $report['reference']['component'] }} has no shelf life configured at this facility, so the
        {{ $report['reference']['shelf_life_days'] }}-day default decides which components are listed by expiry date.
    </div>
@endif

</body>
</html>
