<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDonorNotificationPreferencesRequest;
use App\Http\Requests\UpdateDonorPasswordRequest;
use App\Http\Requests\UpdateDonorProfileRequest;
use App\Service\DonorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonorProfileController extends Controller
{
    public function __construct(
        private readonly DonorService $donorService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->donorService->profile($request->user())
        );
    }

    public function update(UpdateDonorProfileRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorService->updateProfile($request->user(), $request->validated())
        );
    }

    public function updatePassword(UpdateDonorPasswordRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorService->updatePassword($request->user(), $request->validated())
        );
    }

    public function updateNotificationPreferences(UpdateDonorNotificationPreferencesRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorService->updateNotificationPreferences($request->user(), $request->validated())
        );
    }
}
