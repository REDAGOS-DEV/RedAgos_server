<?php

namespace App\Http\Controllers;

use App\Service\CollectionDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Donor/Collection department's dashboard: counts for the signed-in
 * staff member's own centre.
 */
class BloodCenterCollectionDashboardController extends Controller
{
    public function __construct(
        private readonly CollectionDashboardService $collectionDashboardService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->collectionDashboardService->overview($request->user()));
    }
}
