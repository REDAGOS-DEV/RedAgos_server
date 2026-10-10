{{--
    The DOH Blood Request Form (Adult).

    Laid out with tables and absolute font sizes rather than flex or grid,
    because dompdf supports neither. Checkboxes are drawn with borders instead
    of ballot-box glyphs so the form does not depend on one font's coverage of
    the Miscellaneous Symbols block.

    Blanks are ruled lines, not empty space. Everything the system does not
    capture — the physician, the ward, the diagnosis, the crossmatch, the
    handover signatures — is filled in by hand at the counter, exactly as on
    the printed original.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Blood Request Form {{ $request->reference_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 7mm 10mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 6.8pt;
            line-height: 1.17;
            color: #000;
        }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: bottom; padding: 0; }

        .head { text-align: center; line-height: 1.3; }
        .head .country { font-size: 7.6pt; }
        .head .title { font-size: 11.5pt; font-weight: bold; letter-spacing: .3px; }
        .head .adult { font-size: 7.6pt; font-weight: bold; }
        .head .ref { font-size: 6.6pt; margin-top: 1px; }

        .lbl { font-weight: bold; white-space: nowrap; padding-right: 3px; }
        .fill { border-bottom: 1px solid #000; padding: 0 3px; }
        .caption { font-size: 5.8pt; text-align: center; padding-top: 1px; }

        .row { margin-top: 2.6px; }

        /* A checkbox drawn from borders. dompdf gives inline-block a reliable
           box model, which a glyph from an unverified font would not. */
        .box {
            display: inline-block;
            width: 5.8px;
            height: 5.8px;
            border: .7px solid #000;
            margin-right: 3px;
        }
        .box.on { background-color: #000; }

        .component { margin-top: 3px; }
        .component .name { font-weight: bold; }
        .indication { padding-left: 17px; }
        .indication .code { font-weight: bold; }
        .note { padding-left: 30px; font-size: 6pt; }
        .units {
            font-weight: bold;
            border: .8px solid #000;
            padding: 0 4px;
            margin-left: 5px;
        }

        .section { margin-top: 4px; }
        .sig { margin-top: 4px; }
    </style>
</head>
<body>

<div class="head">
    <div class="country">Republic of the Philippines</div>
    <div class="title">BLOOD REQUEST FORM</div>
    <div class="adult">(For ADULT)</div>
    <div class="ref">Reference No. <strong>{{ $request->reference_number }}</strong></div>
</div>

<div class="row" style="margin-top:5px;">
    <table>
        <tr>
            <td class="lbl" style="width:32px;">Date:</td>
            <td class="fill" style="width:27%;">{{ optional($request->request_date)->format('d M Y') }}</td>
            <td style="width:16px;"></td>
            <td class="lbl" style="width:52px;">Hospital:</td>
            <td class="fill">{{ $requestingFacility?->name }}</td>
        </tr>
    </table>
</div>

{{-- Patient block. A replenishment order has no patient, so the lines print
     blank and the purpose is stated where a reader cannot miss it. --}}
<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:105px;">Name of Patient's:</td>
            <td class="fill">{{ $isForPatient ? $patientName : '' }}</td>
            <td class="lbl" style="width:34px; padding-left:6px;">Age:</td>
            <td class="fill" style="width:42px;">{{ $isForPatient ? $request->patient_age : '' }}</td>
            <td class="lbl" style="width:34px; padding-left:6px;">Sex:</td>
            <td class="fill" style="width:48px;">
                {{ $isForPatient && $request->patient_sex ? ucfirst($request->patient_sex) : '' }}
            </td>
        </tr>
        <tr>
            <td></td>
            <td class="caption">Surname &nbsp;&nbsp;&nbsp; First name &nbsp;&nbsp;&nbsp; Middle name</td>
            <td colspan="4"></td>
        </tr>
    </table>
</div>

@unless ($isForPatient)
    <div class="row">
        <strong>Purpose of Request:</strong>
        This request is for <strong>BLOOD BANK REPLENISHMENT</strong> (restocking of hospital blood bank
        inventory). It is not raised for a named patient, so the patient details above are left blank.
        @if ($bloodTypes)
            It restocks several blood types (<strong>{{ $bloodTypes }}</strong>); the units of each are listed
            against its component below.
        @endif
    </div>
@endunless

<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:112px;">Attending Physician:</td>
            <td class="fill"></td>
            <td class="lbl" style="width:38px; padding-left:6px;">Ward</td>
            <td class="fill" style="width:52px;"></td>
            <td class="lbl" style="width:48px; padding-left:6px;">Room #</td>
            <td class="fill" style="width:52px;"></td>
            <td class="lbl" style="width:46px; padding-left:6px;">Hosp. #</td>
            <td class="fill" style="width:58px;"></td>
        </tr>
    </table>
</div>

<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:100px;">Clinical Diagnosis:</td>
            <td class="fill"></td>
        </tr>
    </table>
</div>

<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:118px;">Patient's Blood Type:</td>
            <td class="fill" style="width:70px; text-align:center;"><strong>{{ $bloodGroup }}</strong></td>
            <td class="lbl" style="width:26px; padding-left:8px;">Rh</td>
            <td class="fill" style="width:80px; text-align:center;"><strong>{{ $rhesus }}</strong></td>
            <td></td>
        </tr>
    </table>
</div>

<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:180px;">History of Previous Transfusion: When</td>
            <td class="fill" style="width:130px;"></td>
            <td class="lbl" style="width:42px; padding-left:8px;">Where</td>
            <td class="fill"></td>
        </tr>
    </table>
</div>

<div class="row">
    <span class="lbl">Type of Request:</span>
    <span style="margin-left:8px;"><span class="box {{ $isStat ? '' : 'on' }}"></span>ROUTINE</span>
    <span style="margin-left:16px;"><span class="box {{ $isStat ? 'on' : '' }}"></span>STAT</span>
</div>

<div class="row" style="margin-top:4px;">
    <span class="lbl">Check Components Needed and Indication for Transfusion:</span>
</div>

@foreach ($components as $component)
    <div class="component">
        <span class="box {{ $component['selected'] ? 'on' : '' }}"></span>
        <span class="name">{{ $component['name'] }}</span>
        <span>({{ $component['volume'] }})</span>
        @if ($component['selected'])
            <span class="units">{{ $component['quantity'] }} unit(s)</span>
            @if ($component['by_blood_type'])
                <span>&mdash; {{ $component['by_blood_type'] }}</span>
            @endif
        @endif
    </div>

    @foreach ($component['codes'] as $code)
        <div class="indication">
            <span class="box {{ $code['selected'] ? 'on' : '' }}"></span>
            <span class="code">{{ $code['label'] }}:</span>
            {{ $code['description'] }}
            @if ($code['specified'])
                <strong>{{ $code['specified'] }}</strong>
            @endif
        </div>
    @endforeach

    @if ($component['name'] === 'Washed RBC')
        <div class="note">
            <strong>NOTE: Comments on RBC products:</strong><br>
            1. Document pre and post-transfusion Hb &amp; Hct within 24 hrs.<br>
            2. Dose: Adults &ndash; give on a unit-to-unit basis. 1 unit may suffice to alleviate symptoms
            of anemia. Infants: 10 ml/kg BW.
        </div>
    @endif

    @if ($component['name'] === 'Platelet Concentrate')
        <div class="note">
            <strong>NOTE:</strong> Document platelet count before (within 8 hrs.) and after (within 1 hr.)
            transfusion. Dose: 1 unit/10 kg BW with a maximum of 8 units.
        </div>
    @endif
@endforeach

<div class="section">
    <table>
        <tr>
            <td class="lbl" style="width:112px;">No. of units needed:</td>
            <td class="fill" style="width:150px;"><strong>{{ $totalUnits }}</strong></td>
            <td class="lbl" style="width:130px; padding-left:10px;">No. of Donors Provided:</td>
            <td class="fill"></td>
        </tr>
    </table>
</div>

<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:60px;">Screened:</td>
            <td class="fill" style="width:110px;"></td>
            <td class="lbl" style="width:78px; padding-left:10px;">Unscreened:</td>
            <td class="fill" style="width:110px;"></td>
            <td></td>
        </tr>
    </table>
</div>

<div class="row">
    <span class="lbl">Type of Crossmatching:</span>
    <span style="margin-left:8px;"><span class="box"></span>Saline Phase only</span>
    <span style="margin-left:12px;"><span class="box"></span>Saline, Albumin Phase</span>
    <span style="margin-left:12px;"><span class="box"></span>Saline, Albumin, Globulin Phase</span>
</div>

<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:44px;">Others:</td>
            <td class="fill"></td>
        </tr>
    </table>
</div>

<div class="row">
    <table>
        <tr>
            <td class="lbl" style="width:54px;">Remarks:</td>
            <td class="fill"></td>
        </tr>
    </table>
</div>

<div class="sig">
    <table>
        <tr>
            <td style="width:52%;">
                <div class="fill" style="text-align:center;">
                    {{ trim(($requester?->first_name ?? '').' '.($requester?->last_name ?? '')) }}
                </div>
                <div style="font-size:6.2pt; font-weight:bold; padding-top:1px;">REQUESTING PHYSICIAN</div>
            </td>
            <td style="width:48%; padding-left:14px; font-size:6pt; vertical-align:bottom;">
                Submitted to: {{ $targetFacility?->name }}<br>
                Raised by: {{ $requestingFacility?->name }}
            </td>
        </tr>
    </table>
</div>

<div class="row" style="margin-top:6px;">
    <table>
        <tr>
            <td style="width:50%;">
                <table>
                    <tr>
                        <td class="lbl" style="width:72px;">Received by:</td>
                        <td class="fill"></td>
                    </tr>
                </table>
            </td>
            <td style="width:50%; padding-left:14px;">
                <table>
                    <tr>
                        <td class="lbl" style="width:72px;">Extracted by:</td>
                        <td class="fill"></td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding-top:4px;">
                <table>
                    <tr>
                        <td class="lbl" style="width:62px;">Date/Time</td>
                        <td class="fill"></td>
                    </tr>
                </table>
            </td>
            <td style="padding-top:4px; padding-left:14px;">
                <table>
                    <tr>
                        <td class="lbl" style="width:62px;">Date/Time</td>
                        <td class="fill"></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

</body>
</html>
