<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveReplenishmentScheduleRequest;
use App\Service\ReplenishmentScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A hospital blood bank's request days, one schedule per blood centre it restocks from.
 */
class HospitalReplenishmentScheduleController extends Controller
{
    public function __construct(
        private readonly ReplenishmentScheduleService $scheduleService
    ) {}

    /**
     * List this blood bank's request schedules.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->scheduleService->list($request->user())
        );
    }

    /**
     * Set the days this blood bank sends one centre its weekly request.
     */
    public function update(SaveReplenishmentScheduleRequest $request, int $targetFacility): JsonResponse
    {
        return response()->json(
            $this->scheduleService->save(
                $request->user(),
                $targetFacility,
                $request->validated()['days_of_week']
            )
        );
    }

    /**
     * Stop keeping request days for one centre.
     */
    public function destroy(Request $request, int $targetFacility): JsonResponse
    {
        return response()->json(
            $this->scheduleService->delete($request->user(), $targetFacility)
        );
    }
}
