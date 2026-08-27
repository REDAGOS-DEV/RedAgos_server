<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscardBloodUnitRequest;
use App\Http\Requests\ListInventoryRequest;
use App\Http\Requests\StoreBloodUnitsRequest;
use App\Http\Requests\UpdateBloodUnitRequest;
use App\Service\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterInventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    /**
     * List the caller's facility stock, FEFO-ordered.
     */
    public function index(ListInventoryRequest $request): JsonResponse
    {
        return response()->json(
            $this->inventoryService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Summarise the caller's facility stock.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(
            $this->inventoryService->summary($request->user())
        );
    }

    /**
     * Record collected units against a completed donation.
     */
    public function store(StoreBloodUnitsRequest $request): JsonResponse
    {
        return response()->json(
            $this->inventoryService->record($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Correct a unit's storage location or expiry date.
     */
    public function update(UpdateBloodUnitRequest $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->inventoryService->update($request->user(), $unit, $request->validated())
        );
    }

    /**
     * Record that a unit has physically left the building.
     */
    public function discard(DiscardBloodUnitRequest $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->inventoryService->discard($request->user(), $unit, $request->validated()['reason'])
        );
    }
}
