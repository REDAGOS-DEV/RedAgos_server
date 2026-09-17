<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBloodComponentRequest;
use App\Service\BloodComponentService;
use Illuminate\Http\JsonResponse;

class AdminBloodComponentController extends Controller
{
    public function __construct(
        private readonly BloodComponentService $bloodComponentService
    ) {}

    /**
     * List the component catalogue and which entries are still unconfigured.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->bloodComponentService->index());
    }

    /**
     * Set one component's shelf life and storage temperature.
     */
    public function update(UpdateBloodComponentRequest $request, int $component): JsonResponse
    {
        return response()->json(
            $this->bloodComponentService->update($request->user(), $component, $request->validated())
        );
    }
}
