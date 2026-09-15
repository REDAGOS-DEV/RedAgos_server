<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelBloodRequestRequest;
use App\Http\Requests\ConfirmReceiptRequest;
use App\Http\Requests\ListBloodRequestsRequest;
use App\Http\Requests\StoreBloodRequestRequest;
use App\Service\BloodRequestService;
use App\Service\FulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The requester side: a hospital blood bank raising and tracking its requests.
 */
class HospitalBloodRequestController extends Controller
{
    public function __construct(
        private readonly BloodRequestService $bloodRequestService,
        private readonly FulfillmentService $fulfillmentService
    ) {}

    /**
     * Confirm that dispatched units have arrived.
     */
    public function confirmReceipt(ConfirmReceiptRequest $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->fulfillmentService->confirmReceipt(
                $request->user(),
                $bloodRequest,
                $request->validated()['allocation_ids'] ?? null
            )
        );
    }

    /**
     * List the requests this blood bank has raised.
     */
    public function index(ListBloodRequestsRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Raise a request against a chosen facility.
     */
    public function store(StoreBloodRequestRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->submit($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Show one of this blood bank's requests.
     */
    public function show(Request $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->show($request->user(), $bloodRequest)
        );
    }

    /**
     * Track one request by its reference number.
     */
    public function track(Request $request, string $reference): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->track($request->user(), $reference)
        );
    }

    /**
     * Withdraw a request that has not yet been acted on.
     */
    public function cancel(CancelBloodRequestRequest $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->cancel(
                $request->user(),
                $bloodRequest,
                $request->validated()['reason'] ?? null
            )
        );
    }
}
