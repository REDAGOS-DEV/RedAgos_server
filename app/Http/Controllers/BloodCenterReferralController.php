<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListCounsellingReferralsRequest;
use App\Http\Requests\UpdateCounsellingReferralRequest;
use App\Service\CounsellingReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterReferralController extends Controller
{
    public function __construct(
        private readonly CounsellingReferralService $counsellingReferralService
    ) {}

    /**
     * Page this facility's counselling referrals.
     */
    public function index(ListCounsellingReferralsRequest $request): JsonResponse
    {
        return response()->json(
            $this->counsellingReferralService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * How many referrals nobody has acted on yet, for the sidebar.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->counsellingReferralService->summary($request->user()));
    }

    /**
     * Move a referral on.
     */
    public function update(UpdateCounsellingReferralRequest $request, int $referral): JsonResponse
    {
        return response()->json(
            $this->counsellingReferralService->update($request->user(), $referral, $request->validated())
        );
    }

    /**
     * Ask the donor to contact the centre, by email and in the app.
     */
    public function requestContact(Request $request, int $referral): JsonResponse
    {
        return response()->json(
            $this->counsellingReferralService->requestContact($request->user(), $referral)
        );
    }
}
