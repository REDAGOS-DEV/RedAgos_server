{{--
    The hospital blood bank's Inventory Sheet, as its paper form prints it
    (project owner, 2026-10-28): a CROSSMATCHED and an UNCROSSMATCHED table of
    twenty numbered rows, each cell a bag and its expiry, then who took the
    inventory, the date and who checked it, and the form's document control
    line. One page per sheet: the form's Whole Blood and Packed RBC first,
    then the Other components pages in the same layout, a column each.

    Tables only, with absolute sizes: dompdf supports neither flex nor grid.
--}}
@php
    use Illuminate\Support\Carbon;

    $rows = $sheet['rows'];
    $all = $sheet['by'] === 'all';

    $expiry = fn (?string $date): string => $date ? Carbon::parse($date)->format('m/d/y') : '';

    // The bag, its type when every type shares the sheet, and a mark when it
    // is tagged but still waiting for its crossmatch.
    $label = fn (array $cell): string => $cell['bag_number']
        .($all && $cell['blood_type'] ? ' · '.$cell['blood_type'] : '')
        .($cell['tagged'] ? ' (T)' : '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inventory Sheet — {{ $sheet['facility']['name'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 9mm 10mm 8mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 6.6pt;
            line-height: 1.15;
            color: #000;
        }

        /* dompdf reads no :last-child here; the loop marks the breaks instead. */
        .page--break { page-break-after: always; }

        table { border-collapse: collapse; width: 100%; table-layout: fixed; }

        .head td { vertical-align: bottom; padding: 0 0 4px; }
        .head .facility { font-weight: bold; font-size: 8pt; text-transform: uppercase; }
        .head .type { font-size: 7.4pt; }
        .head .when { text-align: right; font-size: 6.8pt; color: #222; }

        .form th, .form td { border: .6px solid #333; padding: 0 3px; height: 15.5pt; overflow: hidden; white-space: nowrap; }
        .form .section { font-size: 10pt; font-weight: bold; text-align: center; letter-spacing: .3px; height: 17pt; }
        .form .col { font-size: 6.6pt; font-weight: bold; text-align: center; }
        .form .num { text-align: center; font-size: 5.6pt; color: #333; }
        .form .bag { font-size: 6.4pt; }
        .form .exp { text-align: center; font-size: 6.2pt; }

        .sign td { border: .6px solid #333; height: 22pt; vertical-align: top; padding: 2px 3px; font-size: 5.8pt; }
        .sign .value { display: block; margin-top: 5pt; font-size: 7pt; }

        .legend { margin: 3px 0 0; font-size: 5.8pt; color: #333; }

        .control { margin-top: 9pt; }
        .control td { font-size: 6.4pt; font-weight: bold; }
    </style>
</head>
<body>
@foreach ($sheet['sheets'] as $page)
    <div class="page {{ $loop->last ? '' : 'page--break' }}">
        <table class="head">
            <tr>
                <td style="width: 60%">
                    <span class="facility">{{ $sheet['facility']['name'] }}</span><br>
                    <span class="type">
                        {{ $page['blood_type'] ? 'Blood type: '.$page['blood_type'] : 'All blood types' }}
                        @if ($page['kind'] === 'other') · Other components @endif
                    </span>
                </td>
                <td class="when">
                    As of {{ $sheet['as_of_date'] }}, {{ $sheet['as_of_time'] }}
                    @if ($page['pages'] > 1)
                        <br>Page {{ $page['page'] }} of {{ $page['pages'] }}
                    @endif
                </td>
            </tr>
        </table>

        @foreach (['crossmatched' => 'CROSSMATCHED', 'uncrossmatched' => 'UNCROSSMATCHED'] as $key => $title)
            {{-- The title in a table of its own: dompdf's fixed layout takes
                 the column widths from a table's first row, which must not be
                 a merged one. --}}
            <table class="form" style="margin-top: {{ $key === 'uncrossmatched' ? '-.6px' : '0' }}">
                <tr>
                    <th style="width: 4%"></th>
                    <th class="section" style="width: 96%">{{ $title }}</th>
                </tr>
            </table>
            <table class="form" style="margin-top: -.6px">
                <tr>
                    <th style="width: 4%"></th>
                    @foreach ($page[$key] as $column)
                        <th class="col" style="width: 17%">{{ $column['label'] }}</th>
                        <th class="col" style="width: 7%">EXP.</th>
                    @endforeach
                </tr>
                @for ($row = 0; $row < $rows; $row++)
                    <tr>
                        <td class="num">{{ $row + 1 }}</td>
                        @foreach ($page[$key] as $column)
                            @php($cell = $column['cells'][$row] ?? null)
                            <td class="bag">{{ $cell ? $label($cell) : '' }}</td>
                            <td class="exp">{{ $cell ? $expiry($cell['expiry_date']) : '' }}</td>
                        @endforeach
                    </tr>
                @endfor
            </table>
        @endforeach

        <table class="sign" style="margin-top: -.6px">
            <tr>
                <td style="width: 40%">INVENTORY DONE BY:<span class="value">{{ $sheet['prepared_by'] }}</span></td>
                <td style="width: 25%">DATE:<span class="value">{{ $sheet['as_of_date'] }}</span></td>
                <td style="width: 35%">CHECKED BY:</td>
            </tr>
        </table>

        <p class="legend">(T) Tagged to a patient, waiting for crossmatch.</p>

        <table class="control">
            <tr>
                <td style="width: 34%">{{ $sheet['footer']['form_code'] }}</td>
                <td style="width: 33%; text-align: center">{{ $sheet['footer']['effectivity'] }}</td>
                <td style="width: 33%; text-align: right">{{ $sheet['footer']['revision'] }}</td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
