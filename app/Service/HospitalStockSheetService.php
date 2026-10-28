<?php

namespace App\Service;

use App\Enums\HospitalUnitStatus;
use App\Models\BloodComponent;
use App\Models\Facility;
use App\Models\HospitalInventoryReport;
use App\Models\HospitalUnit;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Repository\BloodComponentRepository;
use App\Repository\HospitalInventoryRepository;
use App\Support\BloodGroup;
use App\Support\OperationalDay;
use App\Support\Uploads;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The hospital blood bank's Inventory Sheet, laid out as its paper form.
 *
 * The form (project owner, 2026-10-28) has two tables of twenty rows, each
 * cell a bag and its expiry:
 * - CROSSMATCHED: bags tagged to a patient and crossmatched, ready to
 *   transfuse. Its Whole Blood is listed under TRANSFUSE AS PRBC, as the
 *   owner chose; the form's WB column is left for writing in.
 * - UNCROSSMATCHED: free bags and bags tagged but still waiting for their
 *   crossmatch, which are marked.
 *
 * Whole Blood and Packed RBC are on the form. Every other component follows
 * it on "Other components" pages in the same layout, one column per
 * component, four to a page (project owner, 2026-10-28: "add other
 * components"). Every catalogue component has its column, stocked or not,
 * so the sheet reads the same each day. Only bags in date are listed.
 *
 * The sheets come one per blood type or all types together; when a column
 * runs past twenty bags the sheet continues on another page. One structure
 * feeds the screen and the PDF, so the two can never disagree.
 */
class HospitalStockSheetService
{
    /** The component codes the form lists. */
    private const WHOLE_BLOOD = 'WB';

    private const PACKED_RBC = 'PRBC';

    /** Component columns on an Other components page, as on the form. */
    private const COLUMNS_PER_PAGE = 4;

    /** Where generated reports are kept, on the private uploads disk: never a public URL. */
    private const DIRECTORY = 'hospital-inventory-reports';

    public function __construct(
        private readonly HospitalInventoryRepository $repository,
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly BloodComponentRepository $bloodComponentRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Build the sheets for the caller's blood bank, as of now.
     *
     * The report is configured before it is drawn (project owner, 2026-10-28):
     * the components and blood types it covers, each list null for all of them.
     * The form prints when Whole Blood or Packed RBC is chosen, and only the
     * chosen other components get a column.
     *
     * @param  'type'|'all'  $by  one sheet per blood type, or every type on one
     * @param  array<int, int>|null  $componentIds
     * @param  array<int, int>|null  $bloodTypeIds
     * @return array<string, mixed>
     */
    public function build(User $user, string $by, ?array $componentIds = null, ?array $bloodTypeIds = null): array
    {
        $facility = $this->requireFacility($user);
        $now = OperationalDay::today();
        $rows = (int) config('hospital.inventory_sheet.rows', 20);

        $components = $this->bloodComponentRepository->all()
            ->filter(fn (BloodComponent $component): bool => $componentIds === null || in_array($component->id, $componentIds, true))
            ->sortBy('id');
        $types = $this->bloodCenterRepository->bloodTypes()
            ->filter(fn ($type): bool => $bloodTypeIds === null || in_array($type->id, $bloodTypeIds, true))
            ->sortBy(fn ($type): int => BloodGroup::sortKey($type->code))
            ->values();

        $bags = $this->repository
            ->sheetUnits($facility->id, $now->toDateString())
            ->map(fn (HospitalUnit $unit): array => $this->bag($unit))
            ->filter(fn (array $bag): bool => $components->contains('id', $bag['component_id'])
                && $types->contains('id', $bag['blood_type_id']))
            ->values();

        $isForm = fn (BloodComponent $component): bool => in_array($component->code, [self::WHOLE_BLOOD, self::PACKED_RBC], true);
        $withForm = $components->contains($isForm);

        // Every chosen component the form has no column for, in catalogue order.
        $others = $components
            ->reject($isForm)
            ->map(fn (BloodComponent $component): array => [
                'id' => $component->id,
                'label' => $component->code ?: $component->name,
            ])
            ->values();

        $groups = $by === 'type'
            ? $types->mapWithKeys(fn ($type): array => [$type->code => $bags->where('blood_type_id', $type->id)->values()])
            : collect([null => $bags]);

        $sheets = $groups->flatMap(fn (Collection $group, ?string $code): array => $this->sheets($group, $code ?: null, $rows, $withForm, $others))
            ->values()
            ->all();

        return [
            'facility' => ['name' => $facility->name],
            'by' => $by,
            'rows' => $rows,
            'as_of' => $now->toIso8601String(),
            'as_of_date' => $now->format('F j, Y'),
            'as_of_time' => $now->format('g:i A'),
            'totals' => [
                'crossmatched' => $bags->where('crossmatched', true)->count(),
                'uncrossmatched' => $bags->where('crossmatched', false)->count(),
            ],
            'sheets' => $sheets,
            // What it was configured to cover, as the screen says it.
            'components' => $components->map(fn (BloodComponent $component): string => $component->code ?: $component->name)->values()->all(),
            'blood_types' => $types->pluck('code')->all(),
            // Who took the inventory: whoever drew the sheet. Checked By is signed by hand.
            'prepared_by' => $user->signatureName(),
            'footer' => [
                'form_code' => config('hospital.inventory_sheet.form_code'),
                'effectivity' => config('hospital.inventory_sheet.effectivity'),
                'revision' => config('hospital.inventory_sheet.revision'),
            ],
        ];
    }

    /**
     * Generate a report: build the sheets, print them to a PDF, and keep it.
     *
     * The archive (project owner, 2026-10-29): every report generated is kept
     * as the PDF it was printed as, on the private disk, so a past day's
     * inventory re-downloads exactly as it stood. The sheets come back too,
     * for the screen, drawn from the same data as the stored file.
     *
     * @param  array<int, int>|null  $componentIds
     * @param  array<int, int>|null  $bloodTypeIds
     * @return array{report: array<string, mixed>, sheet: array<string, mixed>}
     */
    public function generate(User $user, string $by, ?array $componentIds = null, ?array $bloodTypeIds = null): array
    {
        $facility = $this->requireFacility($user);
        $sheet = $this->build($user, $by, $componentIds, $bloodTypeIds);

        $pdf = Pdf::loadView('pdf.hospital-inventory-sheet', ['sheet' => $sheet])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->output();

        $path = sprintf('%s/%d/%s.pdf', self::DIRECTORY, $facility->id, Str::uuid());
        Uploads::disk()->put($path, $pdf);

        try {
            $report = HospitalInventoryReport::query()->create([
                'facility_id' => $facility->id,
                'generated_by' => $user->id,
                'layout' => $by,
                'components' => $sheet['components'],
                'blood_types' => $sheet['blood_types'],
                'totals' => $sheet['totals'],
                'pages' => count($sheet['sheets']),
                'file_path' => $path,
                'file_size' => strlen($pdf),
            ]);
        } catch (Throwable $exception) {
            // No row, no file: a stored PDF nothing points at is never reached.
            Uploads::disk()->delete($path);

            throw $exception;
        }

        $this->auditLogger->record($user, 'hospital_inventory.report_generated', $facility, [
            'facility_id' => $facility->id,
            'report_id' => $report->id,
            'layout' => $by,
            'components' => $sheet['components'],
            'blood_types' => $sheet['blood_types'],
        ]);

        return [
            'report' => $this->formatReport($report->setRelation('generatedBy', $user)),
            'sheet' => $sheet,
        ];
    }

    /**
     * The caller's blood bank's generated reports, newest first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function reports(User $user, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($user);

        return HospitalInventoryReport::query()
            ->forFacility($facility->id)
            ->with('generatedBy:id,first_name,last_name,credentials')
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (HospitalInventoryReport $report): array => $this->formatReport($report));
    }

    /**
     * One generated report's PDF, exactly as it was printed.
     *
     * Scoped to the caller's blood bank: another hospital's report is a 404
     * that reveals nothing.
     */
    public function download(User $user, int $reportId): StreamedResponse
    {
        $facility = $this->requireFacility($user);
        $report = HospitalInventoryReport::query()->forFacility($facility->id)->whereKey($reportId)->first();

        if ($report === null || ! Uploads::disk()->exists($report->file_path)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This report was not found.',
                'code' => 'report_not_found',
            ], 404));
        }

        $this->auditLogger->record($user, 'hospital_inventory.report_downloaded', $facility, [
            'facility_id' => $facility->id,
            'report_id' => $report->id,
        ]);

        return Uploads::disk()->download($report->file_path, $this->filename($report), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * The name a report downloads under, stamped with the moment it was generated.
     */
    public function filename(HospitalInventoryReport $report): string
    {
        return 'inventory-sheet-'.$report->created_at->setTimezone(OperationalDay::today()->getTimezone())->format('Y-m-d-Hi').'.pdf';
    }

    /**
     * A generated report as the list shows it.
     *
     * @return array<string, mixed>
     */
    private function formatReport(HospitalInventoryReport $report): array
    {
        return [
            'id' => $report->id,
            'generated_at' => $report->created_at?->toIso8601String(),
            'generated_by' => User::signatureOf($report->generatedBy),
            'layout' => $report->layout,
            'components' => $report->components,
            'blood_types' => $report->blood_types,
            'totals' => $report->totals,
            'pages' => $report->pages,
            'file_size' => $report->file_size,
            'filename' => $this->filename($report),
        ];
    }

    /**
     * One bag as a cell prints it.
     *
     * @return array<string, mixed>
     */
    private function bag(HospitalUnit $unit): array
    {
        $bag = $unit->bloodUnit;

        return [
            // The number staff see: the sender's own for a bag from outside RedAgos.
            'bag_number' => $bag?->directDistribution?->bagNumberFor($unit->unit_id) ?? $unit->unit_id,
            'blood_type' => $bag?->bloodType?->code,
            'blood_type_id' => $bag?->blood_type_id,
            'component' => $bag?->component?->code,
            'component_id' => $bag?->component_id,
            'expiry_date' => $bag?->expiry_date?->toDateString(),
            'crossmatched' => $unit->status === HospitalUnitStatus::TagCrossmatched,
            // Held for a patient but not yet crossmatched: on the shelf, marked.
            'tagged' => $unit->status === HospitalUnitStatus::TagAssigned,
        ];
    }

    /**
     * One group's pages: the form, then its Other components, numbered together.
     *
     * @param  Collection<int, array<string, mixed>>  $bags
     * @param  Collection<int, array{id: int, label: string}>  $others
     * @return array<int, array<string, mixed>>
     */
    private function sheets(Collection $bags, ?string $bloodType, int $rows, bool $withForm, Collection $others): array
    {
        $pages = [...($withForm ? $this->formPages($bags, $rows) : []), ...$this->otherPages($bags, $rows, $others)];

        return array_map(
            fn (array $page, int $index): array => [
                'blood_type' => $bloodType,
                'page' => $index + 1,
                'pages' => count($pages),
                ...$page,
            ],
            $pages,
            array_keys($pages)
        );
    }

    /**
     * The paper form: Whole Blood and Packed RBC, on as many pages as its longest column needs.
     *
     * @param  Collection<int, array<string, mixed>>  $bags
     * @return array<int, array<string, mixed>>
     */
    private function formPages(Collection $bags, int $rows): array
    {
        $crossmatched = $bags->where('crossmatched', true);
        $uncrossmatched = $bags->where('crossmatched', false);

        // Each list fills its columns top to bottom, then the next column.
        $lists = [
            'x_wb' => [$crossmatched->where('component', self::WHOLE_BLOOD)->values(), 1],
            'x_prbc' => [$crossmatched->where('component', self::PACKED_RBC)->values(), 2],
            'u_wb' => [$uncrossmatched->where('component', self::WHOLE_BLOOD)->values(), 1],
            'u_prbc' => [$uncrossmatched->where('component', self::PACKED_RBC)->values(), 3],
        ];

        $pages = max(1, ...array_map(
            fn (array $list): int => (int) ceil($list[0]->count() / ($rows * $list[1])),
            array_values($lists)
        ));

        $sheets = [];

        for ($page = 0; $page < $pages; $page++) {
            $columns = fn (string $key): array => $this->columns($lists[$key][0], $lists[$key][1], $page, $rows);

            [$xWb] = $columns('x_wb');
            [$xPrbc1, $xPrbc2] = $columns('x_prbc');
            [$uWb] = $columns('u_wb');
            [$uPrbc1, $uPrbc2, $uPrbc3] = $columns('u_prbc');

            $sheets[] = [
                'kind' => 'form',
                'crossmatched' => [
                    ['label' => 'TRANSFUSE AS PRBC', 'cells' => $xWb],
                    // Left for writing in: crossmatched Whole Blood is listed as PRBC.
                    ['label' => 'WB', 'cells' => array_fill(0, $rows, null)],
                    ['label' => 'PRBC', 'cells' => $xPrbc1],
                    ['label' => 'PRBC', 'cells' => $xPrbc2],
                ],
                'uncrossmatched' => [
                    ['label' => 'WB', 'cells' => $uWb],
                    ['label' => 'PRBC', 'cells' => $uPrbc1],
                    ['label' => 'PRBC', 'cells' => $uPrbc2],
                    ['label' => 'PRBC', 'cells' => $uPrbc3],
                ],
            ];
        }

        return $sheets;
    }

    /**
     * Every other component, four columns to a page, each column one component.
     *
     * A column past twenty bags continues on the next page, so a set of four
     * takes as many pages as its longest column needs. The last set is padded
     * with blank columns, as the paper form keeps its grid.
     *
     * @param  Collection<int, array<string, mixed>>  $bags
     * @param  Collection<int, array{id: int, label: string}>  $others
     * @return array<int, array<string, mixed>>
     */
    private function otherPages(Collection $bags, int $rows, Collection $others): array
    {
        $sheets = [];

        foreach ($others->chunk(self::COLUMNS_PER_PAGE) as $set) {
            $lists = $set->map(fn (array $component): array => [
                'label' => $component['label'],
                'crossmatched' => $bags->where('component_id', $component['id'])->where('crossmatched', true)->values(),
                'uncrossmatched' => $bags->where('component_id', $component['id'])->where('crossmatched', false)->values(),
            ])->values();

            $pages = max(1, ...$lists->map(fn (array $list): int => (int) ceil(
                max($list['crossmatched']->count(), $list['uncrossmatched']->count()) / $rows
            ))->all());

            for ($page = 0; $page < $pages; $page++) {
                $table = fn (string $section): array => array_pad(
                    $lists->map(fn (array $list): array => [
                        'label' => $list['label'],
                        'cells' => $this->columns($list[$section], 1, $page, $rows)[0],
                    ])->all(),
                    self::COLUMNS_PER_PAGE,
                    ['label' => '', 'cells' => array_fill(0, $rows, null)]
                );

                $sheets[] = [
                    'kind' => 'other',
                    'crossmatched' => $table('crossmatched'),
                    'uncrossmatched' => $table('uncrossmatched'),
                ];
            }
        }

        return $sheets;
    }

    /**
     * The given page's share of a list, cut into columns of `rows` cells, empty cells null.
     *
     * @param  Collection<int, array<string, mixed>>  $bags
     * @return array<int, array<int, array<string, mixed>|null>>
     */
    private function columns(Collection $bags, int $count, int $page, int $rows): array
    {
        $share = $bags->slice($page * $rows * $count, $rows * $count)->values();

        return array_map(
            fn (int $column): array => array_pad(
                $share->slice($column * $rows, $rows)
                    ->map(fn (array $bag): array => [
                        'bag_number' => $bag['bag_number'],
                        'blood_type' => $bag['blood_type'],
                        'expiry_date' => $bag['expiry_date'],
                        'tagged' => $bag['tagged'],
                    ])
                    ->values()
                    ->all(),
                $rows,
                null
            ),
            range(0, $count - 1)
        );
    }

    private function requireFacility(User $user): Facility
    {
        return $user->facility
            ?? throw new HttpResponseException(response()->json([
                'message' => 'This account is not linked to a facility.',
                'code' => 'facility_missing',
            ], 404));
    }
}
