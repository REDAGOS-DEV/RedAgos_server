<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBloodCenterPasswordRequest;
use App\Http\Requests\UpdateBloodCenterProfileRequest;
use App\Http\Requests\UpdateStaffAvatarRequest;
use App\Models\User;
use App\Service\BloodCenterService;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Replace the authenticated staff member's profile photo.
     */
    public function updateAvatar(UpdateStaffAvatarRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->updateAvatar($request->user(), $request->file('avatar'))
        );
    }

    /**
     * Stream a staff member's profile photo from private storage.
     */
    public function showAvatar(string $user): StreamedResponse
    {
        $path = User::where('uuid', $user)->firstOrFail()->profile_image_path;

        abort_if($path === null || ! Uploads::disk()->exists($path), 404);

        return Uploads::disk()->response($path);
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
