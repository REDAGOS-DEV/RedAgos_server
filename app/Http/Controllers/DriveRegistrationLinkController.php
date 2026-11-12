<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDriveGroupRegistrationRequest;
use App\Http\Requests\StoreDriveSelfRegistrationRequest;
use App\Service\DrivePreRegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * A drive's registration link: public, keyed by the link's token alone, and
 * never answering with anyone's details.
 */
class DriveRegistrationLinkController extends Controller
{
    public function __construct(
        private readonly DrivePreRegistrationService $preRegistrationService
    ) {}

    /**
     * The drive's public facts and the self-check.
     */
    public function show(string $token): JsonResponse
    {
        return response()->json($this->preRegistrationService->publicDrive($token));
    }

    /**
     * A person putting themselves on the drive's list.
     */
    public function storeSelf(StoreDriveSelfRegistrationRequest $request, string $token): JsonResponse
    {
        return response()->json($this->preRegistrationService->registerSelf($token, $request->validated()), 201);
    }

    /**
     * A partner organisation putting its people on the drive's list.
     */
    public function storeGroup(StoreDriveGroupRegistrationRequest $request, string $token): JsonResponse
    {
        return response()->json($this->preRegistrationService->registerGroup($token, $request->validated()), 201);
    }
}
