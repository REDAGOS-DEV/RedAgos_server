<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddTransfusionAllocationsRequest;
use App\Http\Requests\CancelBloodRequestRequest;
use App\Http\Requests\CloseRequestLineRequest;
use App\Http\Requests\FindPatientRequestsRequest;
use App\Http\Requests\ListBloodRequestsRequest;
use App\Http\Requests\PlanTransfusionSourcingRequest;
use App\Http\Requests\StoreTransfusionRequestRequest;
use App\Service\TransfusionRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The hospital side of a Patient Transfusion Request: recording a patient's
 * need, splitting it across centres, and following what came of each share.
 */
class HospitalTransfusionRequestController extends Controller
{
    public function __construct(
        private readonly TransfusionRequestService $service
    ) {}

    /**
     * List this blood bank's Patient Transfusion Requests.
     */
    public function index(ListBloodRequestsRequest $request): JsonResponse
    {
        return response()->json(
            $this->service->list(
                $request->user(),
                $request->safe()->only(['status', 'urgency_level', 'request_source', 'search']),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Record a patient's need and ask the chosen centres for their shares.
     */
    public function store(StoreTransfusionRequestRequest $request): JsonResponse
    {
        return response()->json(
            $this->service->create($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Suggest how to split a requirement not yet recorded.
     */
    public function draftSourcing(PlanTransfusionSourcingRequest $request): JsonResponse
    {
        return response()->json(
            $this->service->draftSourcing($request->user(), $request->validated())
        );
    }

    /**
     * Find this hospital's active requirements for a patient, before recording another.
     */
    public function patientMatches(FindPatientRequestsRequest $request): JsonResponse
    {
        return response()->json(
            $this->service->patientMatches($request->user(), $request->validated())
        );
    }

    /**
     * Track a requirement by its PTR reference or an allocation's RQ reference.
     */
    public function track(Request $request, string $reference): JsonResponse
    {
        return response()->json(
            $this->service->track($request->user(), $reference)
        );
    }

    /**
     * Show one requirement with every facility allocation.
     */
    public function show(Request $request, int $transfusionRequest): JsonResponse
    {
        return response()->json(
            $this->service->show($request->user(), $transfusionRequest)
        );
    }

    /**
     * Show everything that happened to a requirement and its allocations.
     */
    public function history(Request $request, int $transfusionRequest): JsonResponse
    {
        return response()->json(
            $this->service->history($request->user(), $transfusionRequest)
        );
    }

    /**
     * Suggest where to ask for whatever is still unallocated.
     */
    public function sourcing(Request $request, int $transfusionRequest): JsonResponse
    {
        return response()->json(
            $this->service->sourcing($request->user(), $transfusionRequest)
        );
    }

    /**
     * Ask more centres for whatever is still unallocated.
     */
    public function addAllocations(AddTransfusionAllocationsRequest $request, int $transfusionRequest): JsonResponse
    {
        return response()->json(
            $this->service->addAllocations($request->user(), $transfusionRequest, $request->validated()),
            201
        );
    }

    /**
     * Withdraw an allocation its centre has not acted on.
     */
    public function withdrawAllocation(CancelBloodRequestRequest $request, int $transfusionRequest, int $allocation): JsonResponse
    {
        return response()->json(
            $this->service->withdrawAllocation(
                $request->user(),
                $transfusionRequest,
                $allocation,
                $request->validated()['reason'] ?? null
            )
        );
    }

    /**
     * Close the rest of one component the patient no longer needs.
     */
    public function closeLine(CloseRequestLineRequest $request, int $transfusionRequest, int $item): JsonResponse
    {
        return response()->json(
            $this->service->closeLine(
                $request->user(),
                $transfusionRequest,
                $item,
                $request->validated()['note'] ?? null
            )
        );
    }

    /**
     * Cancel a requirement nothing has been supplied for.
     */
    public function cancel(CancelBloodRequestRequest $request, int $transfusionRequest): JsonResponse
    {
        return response()->json(
            $this->service->cancel(
                $request->user(),
                $transfusionRequest,
                $request->validated()['reason'] ?? null
            )
        );
    }
}
