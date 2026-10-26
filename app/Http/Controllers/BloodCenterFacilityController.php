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
     * Upload or replace the left-hand logo of the caller's Daily Blood Stock Inventory.
     *
     * Same file rules as the facility logo, so it takes the same request.
     */
    public function uploadSeal(UpdateFacilityLogoRequest $request): JsonResponse
    {
        return response()->json(
            $this->facilityLogoService->uploadSeal($request->user(), $request->file('logo'))
        );
    }

    /**
     * Remove the left-hand logo; the report prints the DOH seal again.
     */
    public function removeSeal(Request $request): JsonResponse
    {
        return response()->json(
            $this->facilityLogoService->removeSeal($request->user())
        );
    }

    /**
     * Stream the left-hand logo. Reached only through a signed, expiring URL.
     */
    public function showSeal(Facility $facility): mixed
    {
        return $this->facilityLogoService->sealResponse($facility);
    }

    /**
     * Stream a facility logo. Reached only through a signed, expiring URL.
     *
     * A billing document's link names the exact file it was issued with; the
     * path is covered by the signature, so it cannot be swapped.
     */
    public function showLogo(Request $request, Facility $facility): mixed
    {
        return $this->facilityLogoService->response($facility, $request->query('path'));
    }
}
