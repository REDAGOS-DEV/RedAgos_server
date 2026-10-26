<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowFinancialReportRequest;
use App\Service\FinancialReportService;
use Illuminate\Http\JsonResponse;

/**
 * Financial Reports: collections, subsidies, receivables and billing by component and hospital, for a period.
 */
class BloodCenterFinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports
    ) {}

    public function show(ShowFinancialReportRequest $request): JsonResponse
    {
        $period = $request->validated();

        return response()->json($this->reports->build($request->user(), $period['from'], $period['to']));
    }
}
