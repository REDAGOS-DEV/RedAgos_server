<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListHospitalSupplyRequestsRequest;
use App\Http\Requests\SupplyTransfusionRequestRequest;
use App\Service\HospitalSupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Other hospitals' Blood Requests, as a hospital blood bank reads them, and sending its near-expiry bags to one.
 */
class HospitalSupplyController extends Controller
{
    public function __construct(
        private readonly HospitalSupplyService $service
    ) {}

    /**
     * List other hospitals' open Blood Requests, with what this hospital could send.
     */
    public function index(ListHospitalSupplyRequestsRequest $request): JsonResponse
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
     * The near-expiry bags this hospital may send for one request.
     */
    public function candidates(Request $request, int $transfusionRequest): JsonResponse
    {
        return response()->json($this->service->candidates($request->user(), $transfusionRequest));
    }

    /**
     * Send the picked bags to the request.
     */
    public function supply(SupplyTransfusionRequestRequest $request, int $transfusionRequest): JsonResponse
    {
        return response()->json(
            $this->service->supply($request->user(), $transfusionRequest, $request->validated('unit_ids')),
            201
        );
    }
}
