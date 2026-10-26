<?php

namespace App\Http\Controllers;

use App\Service\AdminOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Blood Center Admin's dashboard data: role coverage, the team, the
 * centre's setup and recent staff and settings changes (AdminOverviewService).
 */
class BloodCenterAdminOverviewController extends Controller
{
    public function __construct(
        private readonly AdminOverviewService $adminOverviewService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->adminOverviewService->summary($request->user()));
    }
}
