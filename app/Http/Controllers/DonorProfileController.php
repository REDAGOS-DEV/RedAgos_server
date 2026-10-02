<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteDonorAccountRequest;
use App\Http\Requests\StoreDonorIdentityRequest;
use App\Http\Requests\UpdateDonorAvatarRequest;
use App\Http\Requests\UpdateDonorNotificationPreferencesRequest;
use App\Http\Requests\UpdateDonorPasswordRequest;
use App\Http\Requests\UpdateDonorProfileRequest;
use App\Models\User;
use App\Service\AuditLogger;
use App\Service\DonorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DonorProfileController extends Controller
{
    public function __construct(
        private readonly DonorService $donorService,
        private readonly AuditLogger $auditLogger
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
     * Submit an identity document for administrator review.
     */
    public function submitIdentity(StoreDonorIdentityRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorService->submitIdentity(
                $request->user(),
                $request->safe()->except('valid_id_image'),
                $request->file('valid_id_image')
            )
        );
    }

    /**
     * Stream a donor's identity document from private storage.
     *
     * Authenticated and authorised rather than signed, unlike showAvatar()
     * above: a link that opens a government ID without credentials would be
     * forwardable, and would leave no authenticated viewer to record. Every read
     * by somebody other than the donor is written to the audit trail.
     */
    public function showIdentityImage(Request $request, string $uuid): StreamedResponse
    {
        $donor = User::where('uuid', $uuid)->firstOrFail();
        $profile = $donor->donorProfile;

        abort_if($profile === null, 404);

        Gate::authorize('viewIdentityDocument', $profile);

        $path = $profile->valid_id_image_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        if ((int) $donor->id !== (int) $request->user()->id) {
            $this->auditLogger->record($request->user(), 'donor.identity_image_viewed', $profile, [
                'donor_id' => $donor->id,
            ]);
        }

        // no-store keeps the document out of browser and proxy caches, where it
        // would outlive the session that was allowed to see it; nosniff stops a
        // mislabelled upload being reinterpreted as something executable.
        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Close the donor's account after confirming their password.
     */
    public function destroy(DeleteDonorAccountRequest $request): JsonResponse
    {
        return response()->json($this->donorService->deleteAccount($request->user()));
    }
}
