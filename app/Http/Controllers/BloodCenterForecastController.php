<?php

namespace App\Http\Controllers;

use App\Service\DemandForecastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The forecast for the weeks ahead, in the parts the viewer's department
 * plans with (DemandForecastService).
 */
class BloodCenterForecastController extends Controller
{
    public function __construct(
        private readonly DemandForecastService $demandForecastService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->demandForecastService->forecast($request->user()));
    }
}
