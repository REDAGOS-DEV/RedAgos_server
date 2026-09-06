<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacilityDecisionRequest;
use App\Models\Facility;
use App\Service\FacilityApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Decisions a Super Admin makes about a facility that already exists.
 *
 * approve and reject exist for one reason now: facilities that entered
 * pending_approval through the public registration flow, before it was
 * removed. Their records are deliberately left in place, so the Super Admin
 * needs a way to clear them by hand. Nothing this application creates today
 * ever reaches pending_approval — FacilityManagementService activates a
 * facility as it creates it.
 */
class FacilityApprovalController extends Controller
{
    public function __construct(
        private readonly FacilityApprovalService $facilityApprovalService
    ) {}

    /**
     * Approve a legacy pending registration and grant its staff their role.
     */
    public function approve(Request $request, Facility $facility): JsonResponse
    {
        return response()->json(
            $this->facilityApprovalService->approve($request->user(), $facility)
        );
    }

    /**
     * Reject a legacy pending registration, recording why.
     */
    public function reject(FacilityDecisionRequest $request, Facility $facility): JsonResponse
    {
        return response()->json(
            $this->facilityApprovalService->reject($request->user(), $facility, $request->validated()['reason'])
        );
    }
}
