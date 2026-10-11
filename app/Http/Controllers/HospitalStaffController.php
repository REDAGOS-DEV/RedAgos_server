<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHospitalStaffRequest;
use App\Http\Requests\UpdateHospitalStaffRequest;
use App\Service\HospitalStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A hospital blood bank's staff, kept by its Head (hospital.staff.manage).
 */
class HospitalStaffController extends Controller
{
    public function __construct(
        private readonly HospitalStaffService $hospitalStaffService
    ) {}

    /**
     * The blood bank's accounts and the roles a colleague can hold.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->hospitalStaffService->list($request->user()));
    }

    /**
     * Add a colleague with their role.
     */
    public function store(StoreHospitalStaffRequest $request): JsonResponse
    {
        return response()->json(
            $this->hospitalStaffService->create($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Change a colleague's role or title.
     */
    public function update(UpdateHospitalStaffRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->hospitalStaffService->update($request->user(), $uuid, $request->validated())
        );
    }

    /**
     * Remove a colleague's account and end their sessions.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->hospitalStaffService->delete($request->user(), $uuid));
    }

    /**
     * Restore a removed colleague.
     */
    public function restore(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->hospitalStaffService->restore($request->user(), $uuid));
    }
}
