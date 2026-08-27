<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListDonorsRequest;
use App\Http\Requests\LookupDonorRequest;
use App\Http\Requests\StoreWalkInDonorRequest;
use App\Service\DonorDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterDonorController extends Controller
{
    public function __construct(
        private readonly DonorDirectoryService $donorDirectoryService
    ) {}

    /**
     * List the donors this facility has dealt with.
     */
    public function index(ListDonorsRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->browse(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Find one donor by an identifier presented at the counter.
     */
    public function lookup(LookupDonorRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->lookup(
                $request->user(),
                $request->validated('type'),
                $request->validated('value')
            )
        );
    }

    /**
     * Show one donor, in whichever shape this facility is entitled to see.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->show($request->user(), $uuid)
        );
    }

    /**
     * List this facility's donations for one donor.
     */
    public function history(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->history($request->user(), $uuid)
        );
    }

    /**
     * Register a donor who has presented at the counter.
     */
    public function store(StoreWalkInDonorRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorDirectoryService->registerWalkIn($request->user(), $request->validated()),
            201
        );
    }
}
