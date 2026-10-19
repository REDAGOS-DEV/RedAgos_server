<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListSerologyResultsRequest;
use App\Service\SerologyResultService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TTI Testing's results list: every serology panel recorded at this centre.
 * Recording stays on the Testing page; a correction goes through the usual
 * correction route.
 */
class BloodCenterSerologyResultController extends Controller
{
    public function __construct(
        private readonly SerologyResultService $serologyResultService
    ) {}

    public function index(ListSerologyResultsRequest $request): JsonResponse
    {
        return response()->json(
            $this->serologyResultService->list($request->user(), $request->filters(), $request->integer('per_page', 25))
        );
    }

    public function export(ListSerologyResultsRequest $request): StreamedResponse
    {
        return $this->serologyResultService->csv($request->user(), $request->filters());
    }
}
