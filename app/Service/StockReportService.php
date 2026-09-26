<?php

namespace App\Service;

use App\Models\BloodComponent;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Repository\BloodComponentRepository;
use App\Repository\InventoryRepository;
use App\Support\BloodGroup;
use App\Support\OperationalDay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * The Daily Blood Stock Inventory, laid out as SNBC-Mindanao's daily sheet.
 *
 * One structure feeds both the live screen and the PDF, so the two can never
 * disagree about a number. Stock is issuable stock only: `available` and not
 * past its date.
 *
 * The sheet's three tables are kept as the sheet draws them — Rh-positive
 * Packed RBC by expiry; an Rh-negative table; an Rh-positive table of
 * platelets and plasma products — and every other component the catalogue
 * holds goes in a fourth table, so nothing a centre stocks is left off.
 *
 * Which components are broken down by expiry date follows each facility's own
 * shelf life settings: at or under the Packed RBC shelf life gets dated
 * columns, anything longer (frozen plasma products) a total. A component with
 * no shelf life configured is shown as a total and flagged, because nothing
 * about its expiry can be said.
 */
class StockReportService
{
    public function __construct(
        private readonly InventoryRepository $inventoryRepository,
        private readonly BloodComponentRepository $bloodComponentRepository,
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly FacilityLogoService $facilityLogoService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Build the report for the caller's facility, as of now.
     *
     * @return array<string, mixed>
     */
    public function build(User $staff): array
    {
        $facility = $this->requireFacility($staff);
        $now = OperationalDay::today();
        $today = $now->toDateString();
        $tomorrow = $now->addDay()->toDateString();

        $components = $this->components($facility);
        $byId = $components->keyBy('id');
        $byRole = $components->whereNotNull('role')->keyBy('role');

        $codes = $this->bloodCenterRepository->bloodTypes()
            ->pluck('code')
            ->sortBy(fn (string $code): int => BloodGroup::sortKey($code))
            ->values();

        $positive = $codes->filter(fn (string $code): bool => BloodGroup::rh($code) === 'positive')->values();
        $negative = $codes->filter(fn (string $code): bool => BloodGroup::rh($code) === 'negative')->values();

        $counts = $this->counts($facility->id, $today);

        $table = fn (string $key, string $rh, string $title, Collection $rows, array $componentIds): array => $this->table(
            $key, $rh, $title, $rows, $componentIds, $byId, $counts, $today, $tomorrow
        );

        $ids = fn (array $roles): array => collect($roles)
            ->map(fn (string $role): ?int => $byRole->get($role)['id'] ?? null)
            ->filter()
            ->values()
            ->all();

        $extras = $components->whereNull('role')->pluck('id')->all();

        $tables = array_values(array_filter([
            $table('rh_positive_prbc', 'positive', 'RH POSITIVE', $positive, $ids(['prbc'])),
            $table('rh_negative', 'negative', 'RH NEGATIVE', $negative, $ids(['prbc', 'ffp', 'cryo', 'platelets', 'csp'])),
            $table('rh_positive_other', 'positive', 'RH POSITIVE', $positive, $ids(['platelets', 'ffp', 'cryo', 'csp'])),
            $extras === [] ? null : $table('extras', 'all', 'OTHER COMPONENTS', $codes, $extras),
        ], fn (?array $t): bool => $t !== null && $t['columns'] !== []));

        $expiringOn = fn (string $date): int => collect($counts)
            ->flatMap(fn (array $byType): array => array_values($byType))
            ->sum(fn (array $byDate): int => $byDate[$date] ?? 0);

        return [
            'as_of' => $now->toIso8601String(),
            'as_of_date' => strtoupper($now->format('F j, Y')),
            'as_of_time' => $now->format('g:iA'),
            'facility' => [
                'id' => $facility->id,
                'name' => $facility->name,
                'logo_url' => $this->facilityLogoService->urlFor($facility),
            ],
            'header' => config('blood_center.stock_report.header', []),
            'prepared_by' => $this->preparedBy($staff),
            'reference' => $this->reference($components),
            'components' => $components->values()->all(),
            'tables' => $tables,
            'near_expiry' => [
                'today' => $expiringOn($today),
                'tomorrow' => $expiringOn($tomorrow),
            ],
            'unconfigured' => $components
                ->reject(fn (array $c): bool => $c['shelf_life_configured'])
                ->pluck('name')
                ->values()
                ->all(),
        ];
    }

    /**
     * Render the report as the printable sheet.
     */
    public function download(User $staff): Response
    {
        $report = $this->build($staff);
        $facility = $this->requireFacility($staff);

        $this->auditLogger->record($staff, 'inventory.stock_report_downloaded', $facility, [
            'facility_id' => $facility->id,
        ]);

        // dompdf cannot decode a PNG without the GD extension, and fails the
        // whole render if asked to. Without it the sheet still prints — just
        // without the seal and the logo — rather than not at all.
        $images = extension_loaded('gd');

        $pdf = Pdf::loadView('pdf.stock-inventory', [
            'report' => $report,
            'seal' => $images ? $this->sealDataUri() : null,
            'logo' => $images ? $this->facilityLogoService->dataUriFor($facility) : null,
        ])
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

        return $pdf->download($this->filename());
    }

    /**
     * The name the PDF is saved under, stamped with the moment it was taken.
     */
    public function filename(): string
    {
        return 'stock-inventory-'.OperationalDay::today()->format('Y-m-d-Hi').'.pdf';
    }

    /**
     * Every catalogue component with this facility's shelf life and its place on the sheet.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function components(Facility $facility): Collection
    {
        $settings = $this->bloodComponentRepository->settingsFor($facility->id);
        $roles = array_flip(config('blood_center.stock_report.roles', []));

        $components = $this->bloodComponentRepository->all()->map(
            function (BloodComponent $component) use ($settings, $roles): array {
                /** @var FacilityBloodComponent|null $setting */
                $setting = $settings->get($component->id);

                return [
                    'id' => $component->id,
                    'name' => $component->name,
                    'role' => $roles[$component->name] ?? null,
                    'shelf_life_days' => $setting?->shelf_life_days,
                    'shelf_life_configured' => $setting?->hasShelfLife() ?? false,
                ];
            }
        );

        $limit = $this->referenceDays($components);

        return $components->map(fn (array $c): array => [
            ...$c,
            'dated' => $c['shelf_life_configured'] && $c['shelf_life_days'] <= $limit,
        ]);
    }

    /**
     * The shelf life that separates dated components from totals.
     *
     * @param  Collection<int, array<string, mixed>>  $components
     */
    private function referenceDays(Collection $components): int
    {
        $name = config('blood_center.stock_report.reference_component', 'Packed RBC');
        $reference = $components->firstWhere('name', $name);

        return $reference !== null && $reference['shelf_life_configured']
            ? (int) $reference['shelf_life_days']
            : (int) config('blood_center.stock_report.dated_fallback_days', 42);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function reference(Collection $components): array
    {
        $name = config('blood_center.stock_report.reference_component', 'Packed RBC');
        $reference = $components->firstWhere('name', $name);
        $configured = $reference !== null && $reference['shelf_life_configured'];

        return [
            'component' => $name,
            'shelf_life_days' => $this->referenceDays($components),
            'is_fallback' => ! $configured,
        ];
    }

    /**
     * Issuable units as [component_id][blood_type_code][expiry_date] => count.
     *
     * @return array<int, array<string, array<string, int>>>
     */
    private function counts(int $facilityId, string $today): array
    {
        $counts = [];

        foreach ($this->inventoryRepository->stockReportRows($facilityId, $today) as $row) {
            $counts[$row['component_id']][$row['blood_type_code']][$row['expiry_date']] = $row['units'];
        }

        return $counts;
    }

    /**
     * One of the sheet's tables: rows of blood types, a column group per component.
     *
     * @param  Collection<int, string>  $codes
     * @param  array<int, int>  $componentIds
     * @param  Collection<int, array<string, mixed>>  $components
     * @param  array<int, array<string, array<string, int>>>  $counts
     * @return array<string, mixed>
     */
    private function table(
        string $key,
        string $rh,
        string $title,
        Collection $codes,
        array $componentIds,
        Collection $components,
        array $counts,
        string $today,
        string $tomorrow
    ): array {
        $columns = collect($componentIds)
            ->map(fn (int $id): ?array => $components->get($id))
            ->filter()
            ->values();

        $rows = $codes->map(function (string $code) use ($columns, $counts, $today, $tomorrow): array {
            $cells = [];

            foreach ($columns as $component) {
                $byDate = $counts[$component['id']][$code] ?? [];
                ksort($byDate);

                $cells[$component['id']] = [
                    'total' => array_sum($byDate),
                    'by_expiry' => $component['dated']
                        ? array_map(fn (string $date, int $units): array => [
                            'date' => $date,
                            'units' => $units,
                            'expires_today' => $date === $today,
                            'expires_tomorrow' => $date === $tomorrow,
                        ], array_keys($byDate), array_values($byDate))
                        : [],
                    'expiring_soon' => ($byDate[$today] ?? 0) + ($byDate[$tomorrow] ?? 0),
                ];
            }

            return [
                'blood_type' => $code,
                'abo' => BloodGroup::abo($code),
                'rh' => BloodGroup::rh($code),
                'cells' => $cells,
            ];
        })->values();

        $columnInfo = $columns->map(function (array $component) use ($rows): array {
            // As many date slots as the busiest row needs, so every row of a
            // dated column group lines up — at least one, for the blank line
            // the sheet prints when there is no stock.
            $slots = $component['dated']
                ? max(1, $rows->max(fn (array $row): int => count($row['cells'][$component['id']]['by_expiry'])))
                : 0;

            return [
                'id' => $component['id'],
                'name' => $component['name'],
                'role' => $component['role'],
                'dated' => $component['dated'],
                'shelf_life_configured' => $component['shelf_life_configured'],
                'date_slots' => $slots,
                'total' => $rows->sum(fn (array $row): int => $row['cells'][$component['id']]['total']),
            ];
        })->all();

        return [
            'key' => $key,
            'rh' => $rh,
            'title' => $title,
            'columns' => $columnInfo,
            'rows' => $rows->all(),
            'total' => array_sum(array_column($columnInfo, 'total')),
        ];
    }

    /**
     * The sheet's "BY:" line: the signed-in staff member, and their post if recorded.
     */
    private function preparedBy(User $staff): string
    {
        $name = strtoupper(trim($staff->first_name.' '.$staff->last_name));

        return $staff->position ? $name.', '.$staff->position : $name;
    }

    /**
     * The Department of Health seal as a data URI, or null until the file is supplied.
     */
    private function sealDataUri(): ?string
    {
        $path = config('blood_center.stock_report.seal_path');

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * The facility the caller acts for, resolved from the token rather than input.
     */
    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw new HttpResponseException(response()->json([
                'message' => 'This account is not linked to a facility.',
                'code' => 'facility_missing',
            ], 404));
    }
}
