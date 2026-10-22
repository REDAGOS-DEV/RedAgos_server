<?php

namespace App\Http\Controllers;

use App\Service\ProcessingDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Processing department's dashboard: counts for the signed-in staff
 * member's own centre.
 */
class BloodCenterProcessingDashboardController extends Controller
{
    public function __construct(
        private readonly ProcessingDashboardService $processingDashboardService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->processingDashboardService->overview($request->user()));
    }
}
