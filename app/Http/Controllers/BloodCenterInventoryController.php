<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscardBloodUnitRequest;
use App\Http\Requests\ListInventoryRequest;
use App\Http\Requests\StoreBloodUnitsRequest;
use App\Http\Requests\UpdateBloodUnitRequest;
use App\Service\InventoryService;
use App\Service\StockReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BloodCenterInventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly StockReportService $stockReportService
    ) {}

    /**
     * The Daily Blood Stock Inventory, as of now.
     */
    public function stockReport(Request $request): JsonResponse
    {
        return response()->json(
            $this->stockReportService->build($request->user())
        );
    }

    /**
     * The Daily Blood Stock Inventory as the printable sheet.
     */
    public function stockReportPdf(Request $request): Response
    {
        return $this->stockReportService->download($request->user());
    }

    /**
     * List the caller's facility stock, FEFO-ordered.
     */
    public function index(ListInventoryRequest $request): JsonResponse
    {
        return response()->json(
            $this->inventoryService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Summarise the caller's facility stock.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(
            $this->inventoryService->summary($request->user())
        );
    }

    /**
     * List donations cleared for issue that still have units to book in.
     */
    public function intakeQueue(Request $request): JsonResponse
    {
        return response()->json(
            $this->inventoryService->intakeQueue(
                $request->user(),
                $request->integer('per_page', 15),
                // A scanned donation barcode; a string, capped like the column.
                is_string($request->query('barcode')) ? substr($request->query('barcode'), 0, 30) : null
            )
        );
    }

    /**
     * Record collected units against a completed donation.
     */
    public function store(StoreBloodUnitsRequest $request): JsonResponse
    {
        return response()->json(
            $this->inventoryService->record($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Correct a unit's storage location or expiry date.
     */
    public function update(UpdateBloodUnitRequest $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->inventoryService->update($request->user(), $unit, $request->validated())
        );
    }

    /**
     * Record that a unit has physically left the building.
     */
    public function discard(DiscardBloodUnitRequest $request, string $unit): JsonResponse
    {
        return response()->json(
            $this->inventoryService->discard($request->user(), $unit, $request->validated()['reason'])
        );
    }

    /**
     * Release a donation's quarantined units once testing has cleared it.
     */
    public function releaseQuarantine(Request $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->inventoryService->releaseFromQuarantine($request->user(), $donation)
        );
    }

    /**
     * The final labels for a donation's released bags, to print or reprint.
     */
    public function labels(Request $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->inventoryService->labelsFor($request->user(), $donation)
        );
    }
}
