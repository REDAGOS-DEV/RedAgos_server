<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListStaffRequest;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Service\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterStaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staffService
    ) {}

    /**
     * List the caller's own facility roster.
     */
    public function index(ListStaffRequest $request): JsonResponse
    {
        return response()->json(
            $this->staffService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Show one staff account from the caller's facility.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->staffService->show($request->user(), $uuid)
        );
    }

    /**
     * Add a colleague to the caller's facility.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        return response()->json(
            $this->staffService->create($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Update a colleague's department, management level, posting or account status.
     */
    public function update(UpdateStaffRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->staffService->update($request->user(), $uuid, $request->validated())
        );
    }

    /**
     * Remove a colleague's account and end their session.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->staffService->delete($request->user(), $uuid)
        );
    }

    /**
     * Restore a previously removed colleague.
     */
    public function restore(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->staffService->restore($request->user(), $uuid)
        );
    }
}
