<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelBloodRequestRequest;
use App\Http\Requests\CloseRequestLineRequest;
use App\Http\Requests\ConfirmReceiptRequest;
use App\Http\Requests\FindPatientRequestsRequest;
use App\Http\Requests\ListBloodRequestsRequest;
use App\Http\Requests\StoreBloodRequestRequest;
use App\Http\Requests\StoreFollowUpRequestRequest;
use App\Service\BloodRequestService;
use App\Service\FollowUpRequestService;
use App\Service\FulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The requester side: a hospital blood bank raising and tracking its requests.
 */
class HospitalBloodRequestController extends Controller
{
    public function __construct(
        private readonly BloodRequestService $bloodRequestService,
        private readonly FulfillmentService $fulfillmentService,
        private readonly FollowUpRequestService $followUpRequestService
    ) {}

    /**
     * Find this hospital's active requests for a patient, before raising another.
     */
    public function patientMatches(FindPatientRequestsRequest $request): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->patientMatches($request->user(), $request->validated())
        );
    }

    /**
     * Show everything that has happened to one of this blood bank's requests.
     */
    public function history(Request $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->history($request->user(), $bloodRequest)
        );
    }

    /**
     * Close the rest of one line this blood bank no longer needs.
     */
    public function closeLine(CloseRequestLineRequest $request, int $bloodRequest, int $item): JsonResponse
    {
        return response()->json(
            $this->bloodRequestService->closeLine(
                $request->user(),
                $bloodRequest,
                $item,
                $request->validated()['note'] ?? null
            )
        );
    }

    /**
     * Ask another facility for what this request could not get.
     */
    public function followUp(StoreFollowUpRequestRequest $request, int $bloodRequest): JsonResponse
    {
        return response()->json(
            $this->followUpRequestService->createFromPortal($request->user(), $bloodRequest, $request->validated()),
            201
        );
    }

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
     * Download this request as the DOH Blood Request Form.
     */
    public function form(Request $request, int $bloodRequest): Response
    {
        return $this->bloodRequestService->form($request->user(), $bloodRequest);
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
