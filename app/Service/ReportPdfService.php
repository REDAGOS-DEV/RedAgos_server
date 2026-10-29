<?php

namespace App\Service;

use App\Enums\DonorType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * A period's report as a printable PDF, with the centre's letterhead: the
 * Collection report (CollectionReportService) or the centre's
 * (AdminReportService). The figures are the same the screen shows; the PDF
 * lays them out as tables, a section at a time (resources/views/pdf/report).
 */
class ReportPdfService
{
    public function __construct(
        private readonly CollectionReportService $collectionReportService,
        private readonly AdminReportService $adminReportService,
        private readonly FinancialReportService $financialReportService,
        private readonly FacilityLogoService $facilityLogoService
    ) {}

    public function collection(User $staff, string $from, string $to): Response
    {
        $report = $this->collectionReportService->report($staff, $from, $to);

        return $this->render($staff, 'Donor & Collection Report', $report['period'], $this->collectionSections($report), 'collection');
    }

    public function centre(User $staff, string $from, string $to): Response
    {
        $report = $this->adminReportService->report($staff, $from, $to);

        return $this->render($staff, 'Centre Report', $report['period'], $this->centreSections($report), 'centre');
    }

    public function financial(User $staff, string $from, string $to): Response
    {
        $report = $this->financialReportService->build($staff, $from, $to);

        return $this->render($staff, 'Financial Report', $report['period'], $this->financialSections($report), 'financial');
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function render(User $staff, string $title, array $period, array $sections, string $slug): Response
    {
        $staff->loadMissing('facility');
        $facility = $staff->facility;
        $tz = (string) config('blood_center.timezone', 'Asia/Manila');

        // dompdf cannot decode a PNG without the GD extension (StockReportService).
        $images = extension_loaded('gd');
        $sealPath = config('blood_center.stock_report.seal_path');

        $pdf = Pdf::loadView('pdf.report', [
            'header' => config('blood_center.stock_report.header', []),
            'facility' => $facility?->name ?? 'Blood centre',
            'title' => $title,
            'period' => $this->periodLabel($period),
            'sections' => $sections,
            'generated' => CarbonImmutable::now($tz)->format('j F Y, g:i A'),
            'by' => method_exists($staff, 'signatureName') ? $staff->signatureName() : trim("{$staff->first_name} {$staff->last_name}"),
            'seal' => $images ? ($this->facilityLogoService->sealDataUriFor($facility)
                ?? (is_string($sealPath) && is_file($sealPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($sealPath)) : null)) : null,
            'logo' => $images ? $this->facilityLogoService->dataUriFor($facility) : null,
        ])
            ->setPaper('a4', 'landscape')
            ->setOptions(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true, 'defaultFont' => 'DejaVu Sans']);

        return $pdf->download("redagos-{$slug}-report-{$period['from']}-to-{$period['to']}.pdf");
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<int, array<string, mixed>>
     */
    private function collectionSections(array $r): array
    {
        $pct = fn (?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
        $profile = $r['donor_profile'];
        $kind = fn (array $row, string $key): int => $key === 'walk_in_total'
            ? (int) (($row['kind']['walk_in'] ?? 0) + ($row['kind']['ts'] ?? 0) + ($row['kind']['nts'] ?? 0))
            : (int) ($row['kind'][$key] ?? 0);

        $profileRow = fn (string $label, array $row): array => [
            $label,
            $row['sex']['female'] ?? 0, $row['sex']['male'] ?? 0,
            ...array_map(fn (string $k): int => (int) ($row['age'][$k] ?? 0), ['16-17', '18-24', '25-44', '45-64', '65+']),
            $kind($row, 'walk_in_total'), $kind($row, 'ts'), $kind($row, 'nts'), $kind($row, 'drive'),
            ...array_map(fn (DonorType $t): int => (int) ($row['type'][$t->value] ?? 0), [DonorType::FirstTime, DonorType::NewToCentre, DonorType::RepeatRetained, DonorType::Lapsed]),
            $row['total'] ?? 0,
        ];

        return [
            [
                'title' => 'Summary',
                'kpis' => [
                    ['Donations collected', $r['donations']['collected']],
                    ['Donors deferred', $r['donations']['deferred']],
                    ['Deferral rate', $pct($r['donations']['deferral_rate'])],
                    ['Appointments booked', $r['appointments']['booked']],
                    ['Came and donated', $r['appointments']['completed']],
                    ['No-shows', $r['appointments']['no_show']],
                    ['Show rate', $pct($r['appointments']['show_rate'])],
                    ['Drives held', $r['drives']['held']],
                    ['Lapsed donors', $profile['lapsed_donors'] ?? 0],
                ],
            ],
            [
                'title' => 'Collection record (SNBC record)',
                'tables' => [[
                    'groups' => [['', 1], ['Sex', 2], ['Age bracket', 5], ['Type of donor', 4], ['Donor status', 4], ['', 1]],
                    'head' => ['Month', 'Female', 'Male', '16-17', '18-24', '25-44', '45-64', '65+', 'Walk-in', 'TS', 'NTS', 'Mobile drive', 'First time', 'New to centre', 'Repeat', 'Lapsed', 'Total'],
                    'rows' => array_map(fn (array $m): array => $profileRow(CarbonImmutable::parse($m['month'].'-01')->format('F Y'), $m), $profile['months']),
                    'foot' => $profileRow('Total', $profile['total']),
                    'empty' => 'No donations collected in this period.',
                ]],
                'note' => 'Walk-in counts everyone who came to the centre; TS and NTS (for a patient of the same or another blood type) are among them, as picked at the counter.',
            ],
            [
                'title' => 'Donations and deferrals',
                'tables' => [
                    [
                        'title' => 'Donations by blood group',
                        'head' => ['Blood group', 'Donations'],
                        'rows' => array_map(fn (array $g): array => [$g['code'], $g['count']], $r['donations']['by_blood_group']),
                        'empty' => 'No donations in this period.',
                    ],
                    [
                        'title' => 'Deferrals by type',
                        'head' => ['Type', 'Donors'],
                        'rows' => array_map(fn (array $t): array => [$t['type'], $t['count']], $r['deferrals']['by_type']),
                        'empty' => 'No donors deferred in this period.',
                    ],
                    [
                        'title' => 'Most common deferral reasons',
                        'head' => ['Reason', 'Donors'],
                        // The code, and its reason only for a reader allowed it (CollectionReportService).
                        'rows' => array_map(fn (array $x): array => [$x['label'], $x['count']], $r['deferrals']['top_reasons']),
                        'empty' => 'No donors deferred in this period.',
                    ],
                ],
                'side_by_side' => true,
            ],
            [
                'title' => 'Mobile drives',
                'tables' => [[
                    'head' => ['Date', 'Drive', 'Booked', 'Capacity'],
                    'rows' => array_map(fn (array $d): array => [CarbonImmutable::parse($d['date'])->format('j M Y'), $d['name'], $d['booked'], $d['capacity'] ?? '—'], $r['drives']['list']),
                    'empty' => 'No drives in this period.',
                ]],
            ],
            [
                'title' => 'Day by day',
                'tables' => [[
                    'head' => ['Date', 'Donations', 'Deferrals', 'Appointments'],
                    'rows' => array_map(fn (array $d): array => [CarbonImmutable::parse($d['date'])->format('D, j M Y'), $d['donations'], $d['deferrals'], $d['appointments']], $r['daily']),
                    'empty' => 'No days in this period.',
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<int, array<string, mixed>>
     */
    private function centreSections(array $r): array
    {
        $c = $r['centre'];
        $staffLabels = [
            'created' => 'Accounts created', 'role_changes' => 'Role and access changes', 'status_changes' => 'Status changes',
            'removed' => 'Accounts removed', 'restored' => 'Accounts restored', 'sign_ins' => 'Sign-ins', 'failed_sign_ins' => 'Failed sign-ins',
        ];

        return [
            [
                'title' => 'Summary',
                'kpis' => [
                    ['Requests received', $c['requests']['received']],
                    ['Emergency requests', $c['requests']['emergency']],
                    ['Fulfilled', $c['requests']['fulfilled']],
                    ['Partly fulfilled', $c['requests']['partial']],
                    ['Still open', $c['requests']['open']],
                    ['Declined or cancelled', $c['requests']['declined']],
                    ['Hours to fulfil (average)', $c['requests']['avg_hours_to_fulfil'] ?? '—'],
                    ['Units released', $c['units']['released']],
                    ['Units expired', $c['units']['expired']],
                    ['Units discarded', $c['units']['discarded']],
                    ['Donations collected', $c['donations']],
                    ['Donors deferred', $c['deferrals']],
                ],
            ],
            [
                'title' => 'Staff and access',
                'tables' => [
                    [
                        'title' => 'Changes and sign-ins',
                        'head' => ['', 'Count'],
                        'rows' => array_map(fn (string $key, string $label): array => [$label, $r['staff'][$key] ?? 0], array_keys($staffLabels), $staffLabels),
                    ],
                    [
                        'title' => 'Recent account changes',
                        'head' => ['When', 'Change', 'Account', 'By'],
                        'rows' => array_map(fn (array $e): array => [
                            $e['at'] ? CarbonImmutable::parse($e['at'])->setTimezone((string) config('blood_center.timezone', 'Asia/Manila'))->format('j M, g:i A') : '—',
                            $e['label'], $e['subject'] ?? '—', $e['by'],
                        ], $r['staff']['events']),
                        'empty' => 'No staff account changes in this period.',
                    ],
                ],
                'side_by_side' => true,
            ],
            [
                'title' => 'Activity',
                'tables' => [
                    [
                        'title' => 'By module',
                        'head' => ['Module', 'Actions'],
                        'rows' => array_map(fn (array $m): array => [$m['module'], $m['count']], $r['activity']['by_module']),
                        'empty' => 'Nothing recorded in this period.',
                    ],
                    [
                        'title' => 'Most active',
                        'head' => ['Person', 'Actions'],
                        'rows' => array_map(fn (array $p): array => [$p['name'], $p['count']], $r['activity']['top_people']),
                        'empty' => 'Nothing recorded in this period.',
                    ],
                ],
                'side_by_side' => true,
            ],
            [
                'title' => 'Day by day',
                'tables' => [[
                    'head' => ['Date', 'Donations', 'Deferrals', 'Requests'],
                    'rows' => array_map(fn (array $d): array => [CarbonImmutable::parse($d['date'])->format('D, j M Y'), $d['donations'], $d['deferrals'], $d['requests']], $c['daily']),
                    'empty' => 'No days in this period.',
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<int, array<string, mixed>>
     */
    private function financialSections(array $r): array
    {
        $money = static fn ($value): string => '₱'.number_format((float) ($value ?? 0), 2);
        $summary = $r['summary'];
        $collections = $r['collections'];
        $receivables = $r['receivables'];

        return [
            [
                'title' => 'Summary',
                'kpis' => [
                    ['Invoiced', $money($summary['invoiced'])],
                    ['Collected', $money($summary['collected'])],
                    ['Subsidised', $money($summary['subsidised'])],
                    ['Settled by hospitals', $money($summary['settled_by_hospitals'])],
                    ['Outstanding today', $money($summary['outstanding'])],
                    ['Invoices issued', $summary['invoices']],
                    ['Payments recorded', $collections['payments']],
                ],
            ],
            [
                'title' => 'Collections',
                'tables' => [
                    [
                        'title' => 'By payment method',
                        'head' => ['Method', 'Payments', 'Amount'],
                        'rows' => array_map(fn (array $row): array => [$row['label'], $row['payments'], $money($row['amount'])], $collections['by_method']),
                        'foot' => ['Total collected', $collections['payments'], $money($collections['total'])],
                    ],
                    [
                        'title' => 'Voids',
                        'head' => ['Voided payments', 'Amount'],
                        'rows' => [[$collections['voids']['count'], $money($collections['voids']['amount'])]],
                    ],
                    [
                        'title' => 'Collections over time ('.$collections['granularity'].')',
                        'head' => ['Period', 'Amount'],
                        'rows' => array_map(fn (array $row): array => [$row['key'], $money($row['amount'])], $collections['series']),
                        'empty' => 'No collections in this period.',
                    ],
                ],
            ],
            [
                'title' => 'Subsidies',
                'kpis' => [['Requests subsidised', $r['subsidies']['count']], ['Subsidy total', $money($r['subsidies']['amount'])]],
            ],
            [
                'title' => 'Receivables (as of today)',
                'tables' => [
                    ...array_map(fn (string $kind, string $label): array => [
                        'title' => $label.' ('.$money($receivables[$kind]['amount']).')',
                        'head' => ['Age', 'Invoices', 'Amount'],
                        'rows' => array_map(fn (array $age): array => [$age['label'], $age['count'], $money($age['amount'])], $receivables[$kind]['by_age']),
                        'foot' => ['Total', $receivables[$kind]['count'], $money($receivables[$kind]['amount'])],
                    ], ['patients', 'hospitals'], ['Patients', 'Hospitals']),
                ],
                'side_by_side' => true,
            ],
            [
                'title' => 'Hospital settlements recorded in period',
                'tables' => [[
                    'head' => ['Settlements', 'Amount'],
                    'rows' => [[$receivables['settlements']['count'], $money($receivables['settlements']['amount'])]],
                ]],
            ],
            [
                'title' => 'Invoices by hospital',
                'tables' => [[
                    'head' => ['Hospital', 'Invoices', 'Patient', 'Weekly', 'Billed', 'Outstanding'],
                    'rows' => array_map(fn (array $row): array => [$row['name'], $row['invoices'], $row['patient_invoices'], $row['weekly_invoices'], $money($row['billed']), $money($row['outstanding'])], $r['by_hospital']),
                    'empty' => 'No invoices in this period.',
                ]],
            ],
            [
                'title' => 'Invoices by component',
                'tables' => [[
                    'head' => ['Component', 'Units', 'Billed'],
                    'rows' => array_map(fn (array $row): array => [$row['name'], $row['units'], $money($row['amount'])], $r['by_component']),
                    'empty' => 'No invoices in this period.',
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function periodLabel(array $period): string
    {
        $from = CarbonImmutable::parse($period['from']);
        $to = CarbonImmutable::parse($period['to']);

        return $from->isSameDay($to)
            ? $from->format('j F Y')
            : $from->format('j F Y').' to '.$to->format('j F Y').' ('.($period['days'] ?? $from->diffInDays($to) + 1).' days)';
    }
}
