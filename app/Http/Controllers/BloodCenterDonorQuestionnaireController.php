<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitEligibilityScreeningRequest;
use App\Service\DonorQuestionnaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The donor's health questionnaire at the Donation counter: printed for a
 * booked donor who answered it in the Donor Portal, and taken onsite from a
 * walk-in who could not.
 *
 * The physician's review read stays on BloodCenterDonorController under its
 * own ability; these two are deliberately separate abilities from it.
 */
class BloodCenterDonorQuestionnaireController extends Controller
{
    public function __construct(
        private readonly DonorQuestionnaireService $donorQuestionnaireService
    ) {}

    /**
     * The completed questionnaire as the printed DOH form, without review aids.
     */
    public function print(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorQuestionnaireService->printable(
                $request->user(),
                $uuid,
                $request->integer('screening_id') ?: null
            )
        );
    }

    /**
     * The questions this donor is to answer on the counter's device.
     */
    public function form(Request $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorQuestionnaireService->counterForm($request->user(), $uuid)
        );
    }

    /**
     * Record what the donor answered at the counter.
     *
     * The donor's own app request, validated by the same rules: the counter is
     * held to exactly what the app is.
     */
    public function store(SubmitEligibilityScreeningRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->donorQuestionnaireService->recordAtCounter(
                $request->user(),
                $uuid,
                $request->validated(),
                $request->boolean('force')
            ),
            201
        );
    }
}
