<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Service\AuthService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    /**
     * Email a password reset link without disclosing whether the account exists.
     */
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        return response()->json(
            $this->authService->sendPasswordResetLink($request->validated('email'))
        );
    }

    /**
     * Reset the password for a valid reset token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        return response()->json(
            $this->authService->resetPassword($request->validated())
        );
    }
}
