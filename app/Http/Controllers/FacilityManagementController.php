<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeactivateFacilityRequest;
use App\Http\Requests\ListFacilitiesRequest;
use App\Http\Requests\StoreFacilityRequest;
use App\Http\Requests\UpdateFacilityRequest;
use App\Service\FacilityManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Facility onboarding for the Super Admin.
 *
 * Kept apart from FacilityApprovalController, which now only resolves the
 * facilities that entered the queue through the removed public registration
 * flow. Creating a facility and deciding on a legacy application are different
 * operations with different inputs, and folding them together is what let the
 * old code treat "approved" as something that happened to a pending row.
 */
class FacilityManagementController extends Controller
{
    public function __construct(
        private readonly FacilityManagementService $facilityManagementService
    ) {}

    /**
     * List every facility, with its type, primary account and status.
     */
    public function index(ListFacilitiesRequest $request): JsonResponse
    {
        $filters = $request->safe()->only(['status', 'facility_type', 'search']);

        return response()->json(
            $this->facilityManagementService->list($filters, $request->integer('per_page', 15))
        );
    }

    /**
     * Create a facility and its initial primary account.
     */
    public function store(StoreFacilityRequest $request): JsonResponse
    {
        return response()->json(
            $this->facilityManagementService->create($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Correct a facility's details.
     */
    public function update(UpdateFacilityRequest $request, int $facility): JsonResponse
    {
        return response()->json(
            $this->facilityManagementService->update($request->user(), $facility, $request->validated())
        );
    }

    /**
     * Take a facility out of service and sign its staff out.
     */
    public function deactivate(DeactivateFacilityRequest $request, int $facility): JsonResponse
    {
        return response()->json(
            $this->facilityManagementService->deactivate($request->user(), $facility, $request->validated('reason'))
        );
    }

    /**
     * Put a deactivated facility back in service.
     */
    public function reactivate(Request $request, int $facility): JsonResponse
    {
        return response()->json(
            $this->facilityManagementService->reactivate($request->user(), $facility)
        );
    }
}
