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
     * Verify an email address with the code mailed to it.
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $this->authService->verifyEmail($request->validated());

        return response()->json([
            'message' => 'Email address verified successfully.',
        ]);
    }

    /**
     * Mail a new code to the authenticated user.
     */
    public function resend(Request $request): Response
    {
        $this->authService->resendVerificationEmail($request->user());

        return response()->noContent();
    }

    /**
     * Mail a new code to an address supplied by a guest.
     *
     * An unverified account cannot sign in, so it can never reach resend()
     * above. The reply is identical whatever the address turns out to be, so
     * this cannot be used to discover which addresses are registered.
     */
    public function resendForGuest(ResendVerificationEmailRequest $request): JsonResponse
    {
        $this->authService->resendVerificationEmailToAddress($request->validated()['email']);

        return response()->json([
            'message' => 'If that address is registered and still unverified, a new code is on its way.',
        ]);
    }
}
