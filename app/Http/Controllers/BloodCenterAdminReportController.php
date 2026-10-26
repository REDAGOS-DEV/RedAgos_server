<?php

namespace App\Http\Controllers;

use App\Service\AdminReportService;
use App\Service\ReportPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Blood Center Admin's reports for a period (AdminReportService).
 */
class BloodCenterAdminReportController extends Controller
{
    public function __construct(
        private readonly AdminReportService $adminReportService,
        private readonly ReportPdfService $reportPdfService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $this->adminReportService->report($request->user(), $validated['from'], $validated['to'])
        );
    }

    /**
     * The same period's report as a printable PDF (ReportPdfService).
     */
    public function pdf(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->reportPdfService->centre($request->user(), $validated['from'], $validated['to']);
    }
}
