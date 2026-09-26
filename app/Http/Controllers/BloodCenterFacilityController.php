<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFacilityLogoRequest;
use App\Models\Facility;
use App\Service\FacilityLogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterFacilityController extends Controller
{
    public function __construct(
        private readonly FacilityLogoService $facilityLogoService
    ) {}

    /**
     * Upload or replace the caller's facility logo.
     */
    public function uploadLogo(UpdateFacilityLogoRequest $request): JsonResponse
    {
        return response()->json(
            $this->facilityLogoService->upload($request->user(), $request->file('logo'))
        );
    }

    /**
     * Remove the caller's facility logo.
     */
    public function removeLogo(Request $request): JsonResponse
    {
        return response()->json(
            $this->facilityLogoService->remove($request->user())
        );
    }

    /**
     * Stream a facility logo. Reached only through a signed, expiring URL.
     */
    public function showLogo(Facility $facility): mixed
    {
        return $this->facilityLogoService->response($facility);
    }
}
