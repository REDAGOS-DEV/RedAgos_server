<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListDonationsRequest;
use App\Http\Requests\RecordCollectionRequest;
use App\Http\Requests\StoreDonationRequest;
use App\Http\Requests\UpdateDonationStatusRequest;
use App\Http\Requests\VerifyDonorQrRequest;
use App\Service\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterCollectionController extends Controller
{
    public function __construct(
        private readonly CollectionService $collectionService
    ) {}

    /**
     * Show the day's counter queue.
     */
    public function queue(Request $request): JsonResponse
    {
        return response()->json(
            $this->collectionService->queue($request->user(), $request->query('date'))
        );
    }

    /**
     * Verify a scanned QR code and return who is at the counter.
     */
    public function verifyQr(VerifyDonorQrRequest $request): JsonResponse
    {
        return response()->json(
            $this->collectionService->verifyQrToken($request->user(), $request->validated('token'))
        );
    }

    /**
     * Mark an expected donor as arrived.
     */
    public function checkIn(Request $request, int $appointment): JsonResponse
    {
        return response()->json(
            $this->collectionService->checkIn($request->user(), $appointment)
        );
    }

    /**
     * Record that an expected donor never arrived.
     */
    public function noShow(Request $request, int $appointment): JsonResponse
    {
        return response()->json(
            $this->collectionService->markNoShow($request->user(), $appointment)
        );
    }

    /**
     * List this facility's donations.
     */
    public function index(ListDonationsRequest $request): JsonResponse
    {
        return response()->json(
            $this->collectionService->listDonations(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Open a donation for a donor who is present.
     */
    public function store(StoreDonationRequest $request): JsonResponse
    {
        return response()->json(
            $this->collectionService->openDonation($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Move a donation to the next status this department owns.
     */
    public function updateStatus(UpdateDonationStatusRequest $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->collectionService->advance($request->user(), $donation, $request->validated('status'))
        );
    }

    /**
     * Record the physical collection against a donation.
     */
    public function recordCollection(RecordCollectionRequest $request, int $donation): JsonResponse
    {
        return response()->json(
            $this->collectionService->recordCollection($request->user(), $donation, $request->validated()),
            201
        );
    }
}
