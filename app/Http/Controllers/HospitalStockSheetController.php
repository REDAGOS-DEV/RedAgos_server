<?php

namespace App\Http\Controllers;

use App\Http\Requests\HospitalStockSheetRequest;
use App\Service\HospitalStockSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The hospital blood bank's Inventory Reports: its paper inventory form,
 * filled from its own shelf, each one kept as the PDF it was printed as.
 */
class HospitalStockSheetController extends Controller
{
    public function __construct(
        private readonly HospitalStockSheetService $stockSheetService
    ) {}

    /**
     * The blood bank's generated reports, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->stockSheetService->reports($request->user(), min(max($request->integer('per_page', 10), 1), 50))
        );
    }

    /**
     * Generate a report as configured, keep its PDF, and return its sheets.
     */
    public function store(HospitalStockSheetRequest $request): JsonResponse
    {
        return response()->json(
            $this->stockSheetService->generate(
                $request->user(),
                $request->layout(),
                $request->componentIds(),
                $request->bloodTypeIds()
            ),
            201
        );
    }

    /**
     * One generated report's PDF, exactly as it was printed.
     */
    public function pdf(Request $request, int $report): StreamedResponse
    {
        return $this->stockSheetService->download($request->user(), $report);
    }
}
