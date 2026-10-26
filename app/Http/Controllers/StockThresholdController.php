<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveStockThresholdsRequest;
use App\Service\StockThresholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The minimum stock per blood type and component, for a blood centre or a hospital blood bank.
 *
 * One controller serves both portals: the caller's facility comes from the
 * token, and its type decides whose shelf is counted. Who may read or edit is
 * the route's concern, not this controller's.
 */
class StockThresholdController extends Controller
{
    public function __construct(
        private readonly StockThresholdService $stockThresholdService
    ) {}

    /**
     * Show every blood type and component against the facility's minimums.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->stockThresholdService->status($request->user()));
    }

    /**
     * Set, correct or clear the facility's minimums.
     */
    public function update(SaveStockThresholdsRequest $request): JsonResponse
    {
        return response()->json(
            $this->stockThresholdService->save($request->user(), $request->validated()['thresholds'])
        );
    }
}
