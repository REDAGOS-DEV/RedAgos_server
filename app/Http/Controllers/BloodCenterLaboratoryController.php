<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeclareComponentsRequest;
use App\Http\Requests\ListLaboratoryQueueRequest;
use App\Http\Requests\RecordTestResultRequest;
use App\Http\Requests\UpdateLaboratoryStatusRequest;
use App\Service\LaboratoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterLaboratoryController extends Controller
{
    public function __construct(
        private readonly LaboratoryService $laboratoryService
    ) {}

    /**
     * Page the donations awaiting processing.
     */
    public function index(ListLaboratoryQueueRequest $request): JsonResponse
    {
        return response()->json(
            $this->laboratoryService->queue(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Show one donation with everything recorded against it.
     */
    public function show(Request $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->laboratoryService->show($request->user(), $donation)
        );
    }

    /**
     * Record the screening outcome a qualified professional reported.
     */
    public function recordResult(RecordTestResultRequest $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->laboratoryService->recordResult($request->user(), $donation, $request->validated()),
            201
        );
    }

    /**
     * Declare which components the donation was separated into.
     */
    public function declareComponents(DeclareComponentsRequest $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->laboratoryService->declareComponents($request->user(), $donation, $request->validated()),
            201
        );
    }

    /**
     * Clear a donation for issue, or reject it.
     */
    public function updateStatus(UpdateLaboratoryStatusRequest $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->laboratoryService->updateStatus($request->user(), $donation, $request->validated('status'))
        );
    }
}
