<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListDonationRecordsRequest;
use App\Service\DonationRecordService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Donations list: every visit opened at any centre's counter, another
 * centre's read only; the export is this centre's. Closing a visit left open
 * goes through the counter's own status route.
 */
class BloodCenterDonationRecordController extends Controller
{
    public function __construct(
        private readonly DonationRecordService $donationRecordService
    ) {}

    public function index(ListDonationRecordsRequest $request): JsonResponse
    {
        return response()->json(
            $this->donationRecordService->list($request->user(), $request->filters(), $request->integer('per_page', 25))
        );
    }

    public function export(ListDonationRecordsRequest $request): StreamedResponse
    {
        return $this->donationRecordService->csv($request->user(), $request->filters());
    }
}
