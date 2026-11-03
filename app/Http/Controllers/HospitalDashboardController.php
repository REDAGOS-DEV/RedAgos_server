<?php

namespace App\Http\Controllers;

use App\Service\HospitalDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The hospital blood bank's dashboard: counts for the signed-in staff member's
 * own facility. Any role may open it; the service reads only what the role may see.
 */
class HospitalDashboardController extends Controller
{
    public function __construct(
        private readonly HospitalDashboardService $hospitalDashboardService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->hospitalDashboardService->overview($request->user()));
    }
}
