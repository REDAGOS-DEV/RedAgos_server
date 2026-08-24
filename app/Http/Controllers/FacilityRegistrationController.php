<?php

namespace App\Http\Controllers;

use App\Enums\FacilityStatus;
use App\Http\Requests\FacilityDecisionRequest;
use App\Http\Requests\ListFacilityRegistrationsRequest;
use App\Models\Facility;
use App\Service\FacilityApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacilityRegistrationController extends Controller
{
    public function __construct(
        private readonly FacilityApprovalService $facilityApprovalService
    ) {}

    /**
     * List facility registrations awaiting review, or in another given state.
     */
    public function index(ListFacilityRegistrationsRequest $request): JsonResponse
    {
        // Safe to construct directly: the request restricts status to
        // FacilityStatus::values().
        $status = FacilityStatus::from(
            $request->input('status', FacilityStatus::PendingApproval->value)
        );

        return response()->json(
            $this->facilityApprovalService->list($status, $request->integer('per_page', 15))
        );
    }

    /**
     * Approve a registration and grant its staff the blood_center role.
     */
    public function approve(Request $request, Facility $facility): JsonResponse
    {
        return response()->json(
            $this->facilityApprovalService->approve($request->user(), $facility)
        );
    }

    /**
     * Reject a registration, recording why.
     */
    public function reject(FacilityDecisionRequest $request, Facility $facility): JsonResponse
    {
        return response()->json(
            $this->facilityApprovalService->reject($request->user(), $facility, $request->validated()['reason'])
        );
    }

    /**
     * Suspend an approved facility.
     */
    public function suspend(FacilityDecisionRequest $request, Facility $facility): JsonResponse
    {
        return response()->json(
            $this->facilityApprovalService->suspend($request->user(), $facility, $request->validated()['reason'])
        );
    }

    /**
     * Return a suspended facility to service.
     */
    public function reinstate(Request $request, Facility $facility): JsonResponse
    {
        return response()->json(
            $this->facilityApprovalService->reinstate($request->user(), $facility)
        );
    }
}
