<?php

namespace App\Http\Controllers;

use App\Service\TestingDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Testing department's dashboard: counts for the signed-in staff member's
 * own centre.
 */
class BloodCenterTestingDashboardController extends Controller
{
    public function __construct(
        private readonly TestingDashboardService $testingDashboardService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->testingDashboardService->overview($request->user()));
    }
}
