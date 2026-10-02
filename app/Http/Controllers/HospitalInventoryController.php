<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscardBloodUnitRequest;
use App\Http\Requests\ListHospitalInventoryRequest;
use App\Http\Requests\ListUnitTagEventsRequest;
use App\Http\Requests\ReleaseUnitTagRequest;
use App\Http\Requests\TagHospitalUnitRequest;
use App\Service\HospitalInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A hospital blood bank's own stock: the bags it received, and the patient tags placed on them.
 */
class HospitalInventoryController extends Controller
{
    public function __construct(
        private readonly HospitalInventoryService $hospitalInventoryService
    ) {}

    /**
     * List the caller's hospital stock, FEFO-ordered.
     */
    public function index(ListHospitalInventoryRequest $request): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Summarise the caller's hospital stock.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->summary($request->user())
        );
    }

    /**
     * List the tags that ended — untagged or transfused — newest first.
     */
    public function tagEvents(ListUnitTagEventsRequest $request): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->tagEvents(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Show one bag with its full tag history.
     */
    public function show(Request $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->show($request->user(), $unit)
        );
    }

    /**
     * Tag an available bag to a patient.
     */
    public function tag(TagHospitalUnitRequest $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->tag($request->user(), $unit, $request->validated())
        );
    }

    /**
     * Record that crossmatching was completed for the tagged patient.
     */
    public function crossmatch(Request $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->crossmatch($request->user(), $unit)
        );
    }

    /**
     * Record that the crossmatched bag was transfused.
     */
    public function transfuse(Request $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->transfuse($request->user(), $unit)
        );
    }

    /**
     * Release a bag's active tag before its deadline.
     */
    public function release(ReleaseUnitTagRequest $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->release($request->user(), $unit, $request->validated()['reason'])
        );
    }

    /**
     * Confirm a bag pending return is back in storage.
     */
    public function confirmReturn(Request $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->confirmReturn($request->user(), $unit)
        );
    }

    /**
     * Record that a bag has left the hospital's shelf for disposal.
     */
    public function discard(DiscardBloodUnitRequest $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->hospitalInventoryService->discard($request->user(), $unit, $request->validated()['reason'])
        );
    }
}
