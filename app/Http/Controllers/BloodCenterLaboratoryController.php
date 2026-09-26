<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeclareComponentsRequest;
use App\Http\Requests\ListLaboratoryQueueRequest;
use App\Http\Requests\RecordImmunohematologyRequest;
use App\Http\Requests\RecordSerologyRequest;
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
     * Page the donations awaiting testing or processing.
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
     * Record the ABO/Rh typing a medical technologist reported.
     */
    public function recordImmunohematology(RecordImmunohematologyRequest $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->laboratoryService->recordImmunohematology($request->user(), $donation, $request->validated()),
            201
        );
    }

    /**
     * Record the five-marker serology panel a medical technologist reported.
     */
    public function recordSerology(RecordSerologyRequest $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->laboratoryService->recordSerology($request->user(), $donation, $request->validated()),
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
            $this->laboratoryService->updateStatus(
                $request->user(),
                $donation,
                $request->validated('status'),
                $request->validated()
            )
        );
    }
}
