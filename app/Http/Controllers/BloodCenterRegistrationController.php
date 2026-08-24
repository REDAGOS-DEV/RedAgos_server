<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterBloodCenterRequest;
use App\Http\Requests\ResubmitBloodCenterRegistrationRequest;
use App\Service\BloodCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterRegistrationController extends Controller
{
    public function __construct(
        private readonly BloodCenterService $bloodCenterService
    ) {}

    /**
     * Register a blood centre and the staff account applying for it.
     */
    public function register(RegisterBloodCenterRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->register($request->validated()),
            201
        );
    }

    /**
     * Report where the authenticated applicant's registration stands.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->registrationStatus($request->user())
        );
    }

    /**
     * Resubmit a rejected registration for another review.
     */
    public function resubmit(ResubmitBloodCenterRegistrationRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->resubmit($request->user(), $request->validated())
        );
    }
}
