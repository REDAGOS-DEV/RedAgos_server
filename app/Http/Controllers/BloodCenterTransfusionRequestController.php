<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddTransfusionShareRequest;
use App\Http\Requests\ListNetworkTransfusionRequestsRequest;
use App\Service\NetworkTransfusionRequestService;
use Illuminate\Http\JsonResponse;

/**
 * Every hospital's Blood Requests, as any blood centre reads them, and adding this centre's share.
 */
class BloodCenterTransfusionRequestController extends Controller
{
    public function __construct(
        private readonly NetworkTransfusionRequestService $service
    ) {}

    /**
     * List every hospital's Blood Requests, open ones first.
     */
    public function index(ListNetworkTransfusionRequestsRequest $request): JsonResponse
    {
        return response()->json(
            $this->service->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 25)
            )
        );
    }

    /**
     * Add this centre's share of a Blood Request's unallocated units.
     */
    public function share(AddTransfusionShareRequest $request, int $transfusionRequest): JsonResponse
    {
        return response()->json(
            $this->service->addShare($request->user(), $transfusionRequest, $request->validated()),
            201
        );
    }
}
