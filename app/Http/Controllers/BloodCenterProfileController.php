<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBloodCenterPasswordRequest;
use App\Http\Requests\UpdateBloodCenterProfileRequest;
use App\Service\BloodCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterProfileController extends Controller
{
    public function __construct(
        private readonly BloodCenterService $bloodCenterService
    ) {}

    /**
     * Read the authenticated staff member's own profile and facility.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->profile($request->user())
        );
    }

    /**
     * Update the authenticated staff member's own details.
     */
    public function update(UpdateBloodCenterProfileRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->updateProfile($request->user(), $request->validated())
        );
    }

    /**
     * Change the authenticated staff member's password.
     */
    public function updatePassword(UpdateBloodCenterPasswordRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->updatePassword($request->user(), $request->validated())
        );
    }
}
