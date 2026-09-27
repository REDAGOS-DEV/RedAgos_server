<?php

namespace App\Http\Controllers;

use App\Http\Requests\AllocateUnitsRequest;
use App\Http\Requests\CloseRequestLineRequest;
use App\Http\Requests\ListBloodRequestsRequest;
use App\Http\Requests\RejectBloodRequestRequest;
use App\Http\Requests\ReleaseUnitsRequest;
use App\Service\FulfillmentService;
use App\Service\IncomingRequestService;
use App\Service\RequestAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The fulfilling side: a blood centre's incoming queue and what it may do with it.
 */
class BloodCenterRequestController extends Controller
{
    public function __construct(
        private readonly IncomingRequestService $incomingRequestService,
        private readonly RequestAllocationService $requestAllocationService,
        private readonly FulfillmentService $fulfillmentService
    ) {}

    /**
     * Download this incoming request as the DOH Blood Request Form.
     */
    public function form(Request $request, int $bloodRequest): Response
    {
        return $this->incomingRequestService->form($request->user(), $bloodRequest);
    }

    /**
     * List requests addressed to this facility, emergencies first.
     */
    public function index(ListBloodRequestsRequest $request): JsonResponse
    {
        return response()->json(
            $this->incomingRequestService->queue(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Summarise the queue for the dashboard counters.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->incomingRequestService->summary($request->user()));
    }

    /**
     * Show one incoming request beside the stock that could fill it.
     */
    public function show(Request $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->incomingRequestService->review($request->user(), $bloodRequest)
        );
    }

    /**
     * Approve a request and hold stock for it.
     */
    public function allocate(AllocateUnitsRequest $request, int $bloodRequest): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(
            $this->requestAllocationService->allocate(
                $request->user(),
                $bloodRequest,
                $validated['quantity'] ?? null,
                $validated['request_item_id'] ?? null
            )
        );
    }

    /**
     * Refuse a request, recording why.
     */
    public function reject(RejectBloodRequestRequest $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->requestAllocationService->reject(
                $request->user(),
                $bloodRequest,
                $request->validated()['reason']
            )
        );
    }

    /**
     * Give up holds and return their units to stock.
     */
    public function releaseHolds(RejectBloodRequestRequest $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->requestAllocationService->releaseHolds(
                $request->user(),
                $bloodRequest,
                $request->validated()['allocation_ids'] ?? null,
                $request->validated()['reason']
            )
        );
    }

    /**
     * Dispatch the units held for a request.
     */
    public function release(ReleaseUnitsRequest $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->fulfillmentService->release(
                $request->user(),
                $bloodRequest,
                $request->validated()['allocation_ids'] ?? null,
                $request->validated()['handed_to'] ?? null
            )
        );
    }

    /**
     * Close the rest of one line this facility cannot supply.
     */
    public function closeLine(CloseRequestLineRequest $request, int $bloodRequest, int $item): JsonResponse
    {
        return response()->json(
            $this->requestAllocationService->closeLine(
                $request->user(),
                $bloodRequest,
                $item,
                $request->validated()['note'] ?? null
            )
        );
    }

    /**
     * Show everything that has happened to an incoming request.
     */
    public function history(Request $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->incomingRequestService->history($request->user(), $bloodRequest)
        );
    }
}
