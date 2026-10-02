<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMobileEventRequest;
use App\Service\MobileEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterDriveController extends Controller
{
    public function __construct(
        private readonly MobileEventService $mobileEventService
    ) {}

    /**
     * The drives this facility has scheduled, with headline figures.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->list($request->user())
        );
    }

    /**
     * Schedule a new mobile drive.
     */
    public function store(StoreMobileEventRequest $request): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->create($request->user(), $request->validated()),
            201
        );
    }
}
