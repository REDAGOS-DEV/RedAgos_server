<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowFinancialReportRequest;
use App\Service\FinancialReportService;
use App\Service\ReportPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Financial Reports: collections, subsidies, receivables and billing by component and hospital, for a period.
 */
class BloodCenterFinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly ReportPdfService $reportPdfService
    ) {}

    public function show(ShowFinancialReportRequest $request): JsonResponse
    {
        $period = $request->validated();

        return response()->json($this->reports->build($request->user(), $period['from'], $period['to']));
    }

    public function pdf(ShowFinancialReportRequest $request): Response
    {
        $period = $request->validated();

        return $this->reportPdfService->financial($request->user(), $period['from'], $period['to']);
    }
}
