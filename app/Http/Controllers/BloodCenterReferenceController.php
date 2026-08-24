<?php

namespace App\Http\Controllers;

use App\Service\BloodCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterReferenceController extends Controller
{
    public function __construct(
        private readonly BloodCenterService $bloodCenterService
    ) {}

    /**
     * Serve blood types, components, unit statuses and storage locations.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->bloodCenterService->referenceData($request->user())
        );
    }
}
