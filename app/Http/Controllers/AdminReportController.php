<?php

namespace App\Http\Controllers;

use App\Service\NetworkReportService;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Network reports for admins holding admin.reports.export (2026-10-15): one
 * summary for the page and dashboard, and three CSV exports.
 */
class AdminReportController extends Controller
{
    private const DATASETS = ['stock', 'donations', 'requests'];

    public function __construct(private readonly NetworkReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->reports->summary($from, $to));
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate(['dataset' => ['required', 'string', 'in:'.implode(',', self::DATASETS)]]);
        [$from, $to] = $this->range($request);
        $dataset = (string) $request->input('dataset');

        [$header, $rows] = $this->reports->exportRows($dataset, $from, $to);
        $name = sprintf('redagos-%s-%s-to-%s.csv', $dataset, $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            // A UTF-8 byte order mark, so Excel reads accented names right.
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The date range asked for, the last 30 days by default.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : OperationalDay::today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $to->subDays(29);

        return [$from, $to];
    }
}
