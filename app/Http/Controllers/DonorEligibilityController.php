<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitEligibilityScreeningRequest;
use App\Service\EligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonorEligibilityController extends Controller
{
    public function __construct(
        private readonly EligibilityService $eligibilityService
    ) {}

    /**
     * List the current questionnaire without its disqualification flags.
     */
    public function questions(): JsonResponse
    {
        return response()->json($this->eligibilityService->questions());
    }

    /**
     * Return server-derived hints that pre-fill the screening form.
     */
    public function prefill(Request $request): JsonResponse
    {
        return response()->json($this->eligibilityService->prefill($request->user()));
    }

    /**
     * Return the donor's current eligibility state.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->eligibilityService->status($request->user()));
    }

    /**
     * Score a questionnaire submission on the server.
     */
    public function submit(SubmitEligibilityScreeningRequest $request): JsonResponse
    {
        return response()->json(
            $this->eligibilityService->submitScreening(
                $request->user(),
                $request->validated(),
                $request->boolean('force')
            ),
            201
        );
    }

    /**
     * Return the donor's check-in credential details.
     */
    public function qrCode(Request $request): JsonResponse
    {
        return response()->json($this->eligibilityService->qrCode($request->user()));
    }

    /**
     * Mint a replacement check-in token without a fresh screening.
     */
    public function refreshQrCode(Request $request): JsonResponse
    {
        return response()->json($this->eligibilityService->refreshQrCode($request->user()));
    }
}
