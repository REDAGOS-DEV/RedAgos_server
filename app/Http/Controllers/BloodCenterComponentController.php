<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBloodComponentRequest;
use App\Service\BloodComponentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterComponentController extends Controller
{
    public function __construct(
        private readonly BloodComponentService $bloodComponentService
    ) {}

    /**
     * List this facility's component settings.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bloodComponentService->index($request->user()));
    }

    /**
     * Set this facility's shelf life and price for one component.
     */
    public function update(UpdateBloodComponentRequest $request, int $component): JsonResponse
    {
        return response()->json(
            $this->bloodComponentService->update($request->user(), $component, $request->validated())
        );
    }
}
