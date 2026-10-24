<?php

namespace App\Http\Controllers;

use App\Service\TestingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Testing department's reports for a period (TestingReportService).
 */
class BloodCenterTestingReportController extends Controller
{
    public function __construct(
        private readonly TestingReportService $testingReportService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $this->testingReportService->report($request->user(), $validated['from'], $validated['to'])
        );
    }
}
