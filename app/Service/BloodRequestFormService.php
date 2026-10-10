<?php

namespace App\Service;

use App\Enums\IndicationCode;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Support\BloodGroup;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Renders a blood request as the DOH Blood Request Form (Adult).
 *
 * One service for both portals. The requesting hospital and the fulfilling
 * centre are looking at the same sheet of paper — if each built its own, the
 * two copies of a clinical document could disagree, which is the one thing a
 * request form must never do.
 *
 * Fields the system does not capture are rendered as the ruled blanks the
 * printed form has always carried. That is deliberate: the alternative is
 * leaving them off, and a form missing its crossmatch and handover blocks is
 * not the DOH form any more.
 */
class BloodRequestFormService
{
    /**
     * The components in the order the form prints them, with the volumes it quotes.
     *
     * @var array<string, string>
     */
    private const COMPONENT_VOLUMES = [
        'Whole Blood' => 'approximate volume 500 ml',
        'Packed RBC' => 'approximate volume 250 ml',
        'Washed RBC' => 'approximate volume 180 ml',
        'Platelet Concentrate' => 'approximate volume 50 ml',
        'Cryoprecipitate' => 'approximate volume 20 ml',
        'Fresh Frozen Plasma' => 'approximate volume 200-250 ml',
    ];

    /**
     * Render the request as a downloadable PDF.
     */
    public function download(BloodRequest $request): Response
    {
        $pdf = Pdf::loadView('pdf.blood-request-form', $this->viewData($request))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                // The form is built from local markup only. Remote fetching
                // would let a stored value reach the network at render time,
                // and nothing here needs it.
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

        return $pdf->download("BRF-{$request->reference_number}.pdf");
    }

    /**
     * Build everything the form template needs to print one request.
     *
     * @return array<string, mixed>
     */
    public function viewData(BloodRequest $request): array
    {
        $request->loadMissing([
            'items.component',
            'items.bloodType',
            'requestingFacility',
            'targetFacility',
            'bloodType',
            'requester',
        ]);

        // Grouped, not keyed: a weekly request can ask for one component in
        // several blood types, and each of those lines must reach the sheet.
        $selected = $request->items->groupBy(fn (BloodRequestItem $item): string => (string) $item->component?->name);

        return [
            'request' => $request,
            'requestingFacility' => $request->requestingFacility,
            'targetFacility' => $request->targetFacility,
            'requester' => $request->requester,
            'patientName' => $request->patientFullName(),
            'isForPatient' => $request->request_purpose->requiresPatient(),
            'isStat' => $request->urgency_level->isPrioritised(),
            'bloodGroup' => $this->bloodGroup($request),
            'rhesus' => $this->rhesus($request),
            // Set when the lines differ in blood type, which the single
            // Blood Type box cannot hold: each component then names its own.
            'bloodTypes' => $request->blood_type_id === null ? implode(', ', $request->bloodTypeCodes()) : null,
            'totalUnits' => $request->quantity,
            'components' => $this->components($selected, $request->blood_type_id === null),
        ];
    }

    /**
     * Build every component block on the form, ticked where the request asks for it.
     *
     * All six print whether or not they were asked for, because the form is a
     * checklist: an unticked box is information, and a block silently dropped
     * would let a reader assume the option was never offered.
     *
     * A request whose lines differ in blood type prints each component's
     * units per type beside its total — "A+ 4, AB+ 8" — since the form's one
     * Blood Type box cannot say which.
     *
     * @param  Collection<string, Collection<int, BloodRequestItem>>  $selected
     * @return array<int, array<string, mixed>>
     */
    private function components(Collection $selected, bool $mixed): array
    {
        $blocks = [];

        foreach (self::COMPONENT_VOLUMES as $name => $volume) {
            $lines = $selected->get($name);
            // Only a weekly request has several lines of one component, and it
            // carries no indication, so the first line speaks for the block.
            $item = $lines?->first();

            $blocks[] = [
                'name' => $name,
                'volume' => $volume,
                'selected' => $item !== null,
                'quantity' => $lines?->sum('quantity'),
                'by_blood_type' => $mixed && $lines !== null
                    ? $lines
                        ->sortBy(fn (BloodRequestItem $line): int => BloodGroup::sortKey((string) $line->bloodType?->code))
                        ->map(fn (BloodRequestItem $line): string => ($line->bloodType?->code ?? '?').' '.$line->quantity)
                        ->implode(', ')
                    : null,
                'codes' => array_map(
                    fn (IndicationCode $code): array => [
                        'label' => $code->label(),
                        'description' => $code->description(),
                        'selected' => $item?->indication_code === $code,
                        'specified' => $item?->indication_code === $code ? $item->indication_other : null,
                    ],
                    IndicationCode::forComponentName($name)
                ),
            ];
        }

        return $blocks;
    }

    /**
     * Get the ABO group on its own, as the form's "Blood Type" box wants it.
     *
     * Blood types are stored as one code with the rhesus fused on — "AB+" —
     * but the form asks for the group and the rhesus in separate boxes.
     */
    private function bloodGroup(BloodRequest $request): ?string
    {
        return BloodGroup::abo($request->bloodType?->code);
    }

    /**
     * Get the rhesus factor on its own, as the form's "Rh" box wants it.
     */
    private function rhesus(BloodRequest $request): ?string
    {
        $rh = BloodGroup::rh($request->bloodType?->code);

        return $rh === null ? null : ucfirst($rh);
    }
}
