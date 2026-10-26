<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBloodComponentRequest;
use App\Http\Requests\UpdateBloodComponentRequest;
use App\Service\BloodComponentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Processing's side of the component settings: the catalogue and each
 * component's shelf life at this facility. Prices are Billing's, in
 * BloodCenterComponentPriceController.
 */
class BloodCenterComponentController extends Controller
{
    public function __construct(
        private readonly BloodComponentService $bloodComponentService
    ) {}

    /**
     * List this facility's shelf life for each component.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bloodComponentService->index($request->user()));
    }

    /**
     * Add a component to the shared catalogue.
     */
    public function store(StoreBloodComponentRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodComponentService->create($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Set this facility's shelf life for one component.
     */
    public function update(UpdateBloodComponentRequest $request, int $component): JsonResponse
    {
        return response()->json(
            $this->bloodComponentService->updateShelfLife($request->user(), $component, $request->validated())
        );
    }
}
