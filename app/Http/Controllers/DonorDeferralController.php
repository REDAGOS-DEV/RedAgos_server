<?php

namespace App\Http\Controllers;

use App\Service\DonorDeferralStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonorDeferralController extends Controller
{
    public function __construct(
        private readonly DonorDeferralStatusService $deferralStatusService
    ) {}

    /**
     * Whether a deferral stands on the authenticated donor's own record.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->deferralStatusService->forDonor($request->user()));
    }
}
