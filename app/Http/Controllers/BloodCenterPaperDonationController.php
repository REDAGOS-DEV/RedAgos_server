<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordPaperDonationRequest;
use App\Http\Requests\RemovePaperDonationRequest;
use App\Http\Requests\StorePaperSheetRequest;
use App\Http\Requests\StoreWalkInDonorRequest;
use App\Service\PaperDonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A drive's paper records, typed in after the drive.
 */
class BloodCenterPaperDonationController extends Controller
{
    public function __construct(
        private readonly PaperDonationService $paperDonationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, $request->integer('per_page', 25)));

        return response()->json($this->paperDonationService->list($request->user(), $perPage));
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json($this->paperDonationService->options($request->user()));
    }

    public function store(StorePaperSheetRequest $request): JsonResponse
    {
        return response()->json(
            $this->paperDonationService->create($request->user(), $request->validated()),
            201
        );
    }

    public function show(Request $request, int $sheet): JsonResponse
    {
        return response()->json($this->paperDonationService->show($request->user(), $sheet));
    }

    public function destroy(Request $request, int $sheet): JsonResponse
    {
        return response()->json($this->paperDonationService->delete($request->user(), $sheet));
    }

    /**
     * Register a donor named on the paper who is not in RedAgos yet.
     */
    public function storeDonor(StoreWalkInDonorRequest $request): JsonResponse
    {
        return response()->json(
            $this->paperDonationService->registerDonor($request->user(), $request->validated()),
            201
        );
    }

    public function storeDonation(RecordPaperDonationRequest $request, int $sheet): JsonResponse
    {
        return response()->json(
            $this->paperDonationService->record($request->user(), $sheet, $request->validated()),
            201
        );
    }

    public function destroyDonation(RemovePaperDonationRequest $request, int $sheet, int $donation): JsonResponse
    {
        return response()->json(
            $this->paperDonationService->remove($request->user(), $sheet, $donation, $request->validated('reason'))
        );
    }
}
