<?php

namespace App\Http\Controllers;

use App\Service\CollectionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Donor & Collection department's reports for a period (CollectionReportService).
 */
class BloodCenterCollectionReportController extends Controller
{
    public function __construct(
        private readonly CollectionReportService $collectionReportService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $this->collectionReportService->report($request->user(), $validated['from'], $validated['to'])
        );
    }
}
