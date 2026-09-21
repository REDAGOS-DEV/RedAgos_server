<?php

namespace App\Http\Controllers;

use App\Service\BloodRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HospitalReferenceController extends Controller
{
    public function __construct(
        private readonly BloodRequestService $bloodRequestService
    ) {}

    /**
     * Serve the blood types, components and indication codes a request form needs.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->referenceData($request->user())
        );
    }
}
