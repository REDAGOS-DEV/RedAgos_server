<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveComponentPricesRequest;
use App\Service\BloodComponentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Billing's side of the component settings: what this facility charges per unit.
 */
class BloodCenterComponentPriceController extends Controller
{
    public function __construct(
        private readonly BloodComponentService $bloodComponentService
    ) {}

    /**
     * List this facility's price for each component.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bloodComponentService->prices($request->user()));
    }

    /**
     * Set this facility's price, or no charge, for every component at once.
     */
    public function update(SaveComponentPricesRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodComponentService->savePrices($request->user(), $request->validated()['prices'])
        );
    }
}
