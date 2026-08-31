<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResendVerificationEmailRequest;
use App\Http\Requests\VerifyEmailRequest;
use App\Service\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    /**
     * Verify an email address from a signed verification link.
     */
    public function verify(VerifyEmailRequest $request): JsonResponse|Response
    {
        $result = $this->authService->verifyEmail($request->validated());

        if ($result['already_verified']) {
            return response()->noContent();
        }

        return response()->json([
            'message' => 'Email address verified successfully.',
        ]);
    }

    /**
     * Re-send the verification email to the authenticated user.
     */
    public function resend(Request $request): Response
    {
        $this->authService->resendVerificationEmail($request->user());

        return response()->noContent();
    }

    /**
     * Re-send the verification email to an address supplied by a guest.
     *
     * An unverified account cannot sign in, so it can never reach resend()
     * above. The reply is identical whatever the address turns out to be, so
     * this cannot be used to discover which addresses are registered.
     */
    public function resendForGuest(ResendVerificationEmailRequest $request): JsonResponse
    {
        $this->authService->resendVerificationEmailToAddress($request->validated()['email']);

        return response()->json([
            'message' => 'If that address is registered and still unverified, a new verification link is on its way.',
        ]);
    }
}
