<?php

namespace App\Http\Controllers;

use App\Http\Requests\LiftDonorDeferralRequest;
use App\Http\Requests\ListDonorDeferralsRequest;
use App\Http\Requests\StoreDonorDeferralRequest;
use App\Service\ManualDeferralService;
use Illuminate\Http\JsonResponse;

/**
 * The Deferrals page: deferrals recorded on a donor by hand, outside a
 * visit's screening, and lifted later.
 */
class BloodCenterDeferralController extends Controller
{
    public function __construct(
        private readonly ManualDeferralService $manualDeferralService
    ) {}

    /**
     * List the deferrals this centre recorded.
     */
    public function index(ListDonorDeferralsRequest $request): JsonResponse
    {
        return response()->json(
            $this->manualDeferralService->list($request->user(), $request->filters(), $request->integer('per_page', 25))
        );
    }

    /**
     * Record a deferral on a donor.
     */
    public function store(StoreDonorDeferralRequest $request): JsonResponse
    {
        return response()->json(
            $this->manualDeferralService->record($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Lift a deferral this centre recorded.
     */
    public function lift(LiftDonorDeferralRequest $request, int $deferral): JsonResponse
    {
        return response()->json(
            $this->manualDeferralService->lift($request->user(), $deferral, $request->validated('reason'))
        );
    }
}
