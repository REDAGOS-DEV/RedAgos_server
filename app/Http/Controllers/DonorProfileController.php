<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteDonorAccountRequest;
use App\Http\Requests\UpdateDonorAvatarRequest;
use App\Http\Requests\UpdateDonorNotificationPreferencesRequest;
use App\Http\Requests\UpdateDonorPasswordRequest;
use App\Http\Requests\UpdateDonorProfileRequest;
use App\Models\User;
use App\Service\DonorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DonorProfileController extends Controller
{
    public function __construct(
        private readonly DonorService $donorService
    ) {}

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

    /**
     * Replace the donor's profile photo.
     */
    public function updateAvatar(UpdateDonorAvatarRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorService->updateAvatar($request->user(), $request->file('avatar'))
        );
    }

    /**
     * Stream the donor's profile photo from private storage.
     */
    public function showAvatar(string $user): StreamedResponse
    {
        $path = User::where('uuid', $user)->firstOrFail()->donorProfile?->profile_image_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    /**
     * Close the donor's account after confirming their password.
     */
    public function destroy(DeleteDonorAccountRequest $request): JsonResponse
    {
        return response()->json($this->donorService->deleteAccount($request->user()));
    }
}
