<?php

namespace App\Http\Controllers;

use App\Service\CollectionRecordExportService;
use App\Service\CollectionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Donor & Collection department's reports for a period (CollectionReportService).
 */
class BloodCenterCollectionReportController extends Controller
{
    public function __construct(
        private readonly CollectionReportService $collectionReportService,
        private readonly CollectionRecordExportService $collectionRecordExportService
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

    /**
     * The collection record (SNBC record) for the period, as the centre's own
     * Excel sheet (CollectionRecordExportService).
     */
    public function snbcRecord(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        $file = $this->collectionRecordExportService->export($request->user(), $validated['from'], $validated['to']);

        return response($file['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }
}
