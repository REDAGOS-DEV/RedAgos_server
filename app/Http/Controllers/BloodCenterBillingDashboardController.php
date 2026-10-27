<?php

namespace App\Http\Controllers;

use App\Service\BillingDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Billing department's dashboard (BillingDashboardService).
 */
class BloodCenterBillingDashboardController extends Controller
{
    public function __construct(
        private readonly BillingDashboardService $billingDashboardService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->billingDashboardService->overview($request->user()));
    }
}
