<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListDirectDistributionsRequest;
use App\Http\Requests\ReceiveDirectDistributionRequest;
use App\Http\Requests\StoreExternalBloodSourceRequest;
use App\Service\DirectDistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bags a hospital blood bank received from outside RedAgos, for its patients' transfusion requests.
 */
class HospitalDirectDistributionController extends Controller
{
    public function __construct(
        private readonly DirectDistributionService $directDistributionService
    ) {}

    /**
     * List this blood bank's external receipts, newest first.
     */
    public function index(ListDirectDistributionsRequest $request): JsonResponse
    {
        return response()->json(
            $this->directDistributionService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Receive one external bag and put it on this blood bank's shelf.
     */
    public function store(ReceiveDirectDistributionRequest $request): JsonResponse
    {
        return response()->json(
            $this->directDistributionService->receive($request->user(), $request->validated()),
            201
        );
    }

    /**
     * List the blood services a bag can be received from.
     */
    public function sources(Request $request): JsonResponse
    {
        return response()->json(
            $this->directDistributionService->sources($request->user())
        );
    }

    /**
     * Add a blood service to the list.
     */
    public function storeSource(StoreExternalBloodSourceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(
            $this->directDistributionService->addSource($request->user(), $validated['name'], $validated['code'] ?? null),
            201
        );
    }
}
