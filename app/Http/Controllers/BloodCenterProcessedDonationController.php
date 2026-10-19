<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListProcessedDonationsRequest;
use App\Service\ProcessedDonationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Processing's Processed list: every donation it has recorded a breakdown
 * for. The hand-over itself goes through the laboratory status route.
 */
class BloodCenterProcessedDonationController extends Controller
{
    public function __construct(
        private readonly ProcessedDonationService $processedDonationService
    ) {}

    public function index(ListProcessedDonationsRequest $request): JsonResponse
    {
        return response()->json(
            $this->processedDonationService->list($request->user(), $request->filters(), $request->integer('per_page', 25))
        );
    }

    public function export(ListProcessedDonationsRequest $request): StreamedResponse
    {
        return $this->processedDonationService->csv($request->user(), $request->filters());
    }
}
