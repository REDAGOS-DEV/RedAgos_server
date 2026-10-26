<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListDeferralCodesRequest;
use App\Http\Requests\StoreDeferralCodeRequest;
use App\Http\Requests\UpdateDeferralCodeRequest;
use App\Service\DeferralCodeService;
use Illuminate\Http\JsonResponse;

/**
 * The centre's deferral codes: the list every deferral is recorded from, and
 * the Screening Physician's page that keeps it.
 */
class BloodCenterDeferralCodeController extends Controller
{
    public function __construct(
        private readonly DeferralCodeService $deferralCodeService
    ) {}

    /**
     * List this centre's deferral codes.
     */
    public function index(ListDeferralCodesRequest $request): JsonResponse
    {
        return response()->json(
            $this->deferralCodeService->index($request->user(), $request->boolean('include_retired'))
        );
    }

    /**
     * Add a deferral code.
     */
    public function store(StoreDeferralCodeRequest $request): JsonResponse
    {
        return response()->json(
            $this->deferralCodeService->create($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Change, retire or restore a deferral code.
     */
    public function update(UpdateDeferralCodeRequest $request, int $code): JsonResponse
    {
        return response()->json(
            $this->deferralCodeService->update($request->user(), $code, $request->validated())
        );
    }
}
