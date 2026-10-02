<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchAvailabilityRequest;
use App\Service\BloodAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Network stock lookups for hospital blood banks choosing where to send a request.
 */
class HospitalAvailabilityController extends Controller
{
    public function __construct(
        private readonly BloodAvailabilityService $bloodAvailabilityService
    ) {}

    /**
     * Search participating facilities for a blood type and component.
     */
    public function index(SearchAvailabilityRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodAvailabilityService->search($request->user(), $request->validated())
        );
    }

    /**
     * List the facilities this blood bank may address a request to.
     */
    public function facilities(Request $request): JsonResponse
    {
        return response()->json(
            $this->bloodAvailabilityService->eligibleTargets($request->user())
        );
    }
}
