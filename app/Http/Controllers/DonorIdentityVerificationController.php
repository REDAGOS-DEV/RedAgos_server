<?php

namespace App\Http\Controllers;

use App\Enums\IdentityStatus;
use App\Http\Requests\DonorIdentityDecisionRequest;
use App\Http\Requests\ListDonorIdentitySubmissionsRequest;
use App\Service\DonorIdentityVerificationService;
use Illuminate\Http\JsonResponse;

class DonorIdentityVerificationController extends Controller
{
    public function __construct(
        private readonly DonorIdentityVerificationService $identityVerificationService
    ) {}

    /**
     * List identity documents awaiting review, or in another given state.
     */
    public function index(ListDonorIdentitySubmissionsRequest $request): JsonResponse
    {
        // Safe to construct directly: the request restricts status to
        // IdentityStatus::values().
        $status = IdentityStatus::from(
            $request->input('status', IdentityStatus::Pending->value)
        );

        return response()->json(
            $this->identityVerificationService->list($status, $request->integer('per_page', 15))
        );
    }

    /**
     * Verify a donor's identity document.
     */
    public function approve(DonorIdentityDecisionRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->identityVerificationService->approve(
                $request->user(),
                $uuid,
                (int) $request->validated('submission_version')
            )
        );
    }

    /**
     * Reject a donor's identity document, recording why.
     */
    public function reject(DonorIdentityDecisionRequest $request, string $uuid): JsonResponse
    {
        return response()->json(
            $this->identityVerificationService->reject(
                $request->user(),
                $uuid,
                (int) $request->validated('submission_version'),
                $request->validated('reason')
            )
        );
    }
}
