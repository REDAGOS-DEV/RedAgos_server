<?php

namespace App\Http\Controllers;

use App\Http\Requests\FindWalkInDuplicatesRequest;
use App\Http\Requests\StoreWalkInBloodRequestRequest;
use App\Service\WalkInRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Walk-in Patient Transfusion requests, recorded at the blood centre counter.
 */
class BloodCenterWalkInController extends Controller
{
    public function __construct(
        private readonly WalkInRequestService $walkInRequestService
    ) {}

    /**
     * Serve the hospitals, components and ID types the walk-in form needs.
     */
    public function reference(Request $request): JsonResponse
    {
        return response()->json($this->walkInRequestService->formReference($request->user()));
    }

    /**
     * Look for a request the hospital already has open for this patient.
     */
    public function duplicates(FindWalkInDuplicatesRequest $request): JsonResponse
    {
        return response()->json(
            $this->walkInRequestService->findDuplicates($request->user(), $request->validated())
        );
    }

    /**
     * Record a walk-in request the hospital has confirmed by phone.
     */
    public function store(StoreWalkInBloodRequestRequest $request): JsonResponse
    {
        return response()->json(
            $this->walkInRequestService->record($request->user(), $request->validated()),
            201
        );
    }
}
