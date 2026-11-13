<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListFacilitiesRequest;
use App\Http\Requests\StoreFacilityRequest;
use App\Http\Requests\UpdateFacilityAbbreviationRequest;
use App\Service\FacilityManagementService;
use Illuminate\Http\JsonResponse;

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
     * Set or correct a blood centre's abbreviation, until it has booked a bag.
     */
    public function updateAbbreviation(UpdateFacilityAbbreviationRequest $request, int $facility): JsonResponse
    {
        return response()->json(
            $this->facilityManagementService->setAbbreviation($request->user(), $facility, $request->validated('abbreviation'))
        );
    }
}
