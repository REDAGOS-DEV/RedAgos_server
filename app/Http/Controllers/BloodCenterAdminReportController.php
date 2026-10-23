<?php

namespace App\Http\Controllers;

use App\Service\AdminReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Blood Center Admin's reports for a period (AdminReportService).
 */
class BloodCenterAdminReportController extends Controller
{
    public function __construct(
        private readonly AdminReportService $adminReportService
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
}
